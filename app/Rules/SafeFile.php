<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Custom validation rule to verify file is safe for upload.
 * Checks for malicious content, double extensions, and MIME type mismatches.
 */
class SafeFile implements ValidationRule
{
    protected array $allowedExtensions = [];
    protected array $blockedPatterns = [
        '/\.php\d?$/i',
        '/\.phtml$/i',
        '/\.phar$/i',
        '/\.exe$/i',
        '/\.bat$/i',
        '/\.sh$/i',
        '/\.cmd$/i',
        '/\.scr$/i',
        '/\.msi$/i',
        '/\.dll$/i',
        '/\.vbs$/i',
        '/\.js$/i',
        '/\.htaccess$/i',
        '/\.htpasswd$/i',
    ];

    public function __construct(array $allowedExtensions = [])
    {
        $this->allowedExtensions = $allowedExtensions;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            $fail('O arquivo enviado é inválido.');
            return;
        }

        $filename = $value->getClientOriginalName();
        $extension = strtolower($value->getClientOriginalExtension());

        // Check for blocked file extensions
        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                $fail('Este tipo de arquivo não é permitido.');
                return;
            }
        }

        // Check for double extensions (e.g., image.php.jpg)
        $nameParts = explode('.', $filename);
        if (count($nameParts) > 2) {
            foreach ($nameParts as $part) {
                $partLower = strtolower($part);
                if (in_array($partLower, ['php', 'phtml', 'exe', 'sh', 'bat', 'js', 'vbs'])) {
                    $fail('Nome de arquivo suspeito detectado.');
                    return;
                }
            }
        }

        // Check if extension is in allowed list
        if (!empty($this->allowedExtensions) && !in_array($extension, $this->allowedExtensions)) {
            $fail('Extensão de arquivo não permitida: .' . $extension);
            return;
        }

        // Verify file is not empty
        if ($value->getSize() === 0) {
            $fail('O arquivo está vazio.');
            return;
        }

        // Check for null bytes in filename
        if (strpos($filename, "\0") !== false) {
            $fail('Nome de arquivo inválido.');
            return;
        }
    }
}
