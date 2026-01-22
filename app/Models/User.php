<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'cover_url',
        'bio',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
        'computed_avatar_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the full avatar URL from R2.
     */
    protected function computedAvatarUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (empty($this->avatar_url)) {
                    return '/assets/images/default-avatar.jpg';
                }

                // If already a full URL
                if (str_starts_with($this->avatar_url, 'http')) {
                    return $this->avatar_url;
                }

                // Build R2 URL
                $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');

                return $r2Url.'/'.ltrim($this->avatar_url, '/');
            }
        );
    }

    /**
     * Get the full cover URL from R2.
     */
    protected function computedCoverUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (empty($this->cover_url)) {
                    return null;
                }

                // If already a full URL
                if (str_starts_with($this->cover_url, 'http')) {
                    return $this->cover_url;
                }

                // Build R2 URL
                $r2Url = rtrim(config('filesystems.disks.r2.url', env('R2_URL', '')), '/');

                return $r2Url.'/'.ltrim($this->cover_url, '/');
            }
        );
    }
}
