<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['name', 'avatar', 'email', 'password', 'is_admin', 'is_view_only', 'locale', 'github_id', 'github_token', 'github_refresh_token'])]
#[Hidden(['password', 'remember_token', 'github_token', 'github_refresh_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['avatar_url', 'has_password'];

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
            'is_admin' => 'boolean',
            'is_view_only' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function testSuites(): BelongsToMany
    {
        return $this->belongsToMany(TestSuite::class, 'test_suite_user')
            ->withPivot(['can_view', 'can_edit', 'can_delete', 'can_run'])
            ->withTimestamps();
    }

    public function bookmarkedSuites(): BelongsToMany
    {
        return $this->belongsToMany(TestSuite::class, 'suite_bookmarks')->withTimestamps();
    }

    /**
     * Display name, truncated for compact UI surfaces. Names longer than 32
     * characters are shown as 31 characters plus an ellipsis.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (?string $name) => Str::length($name) > 32
            ? Str::limit($name, 31, '…')
            : $name);
    }

    /**
     * True when the user has a password set (false for OAuth-only users).
     */
    protected function hasPassword(): Attribute
    {
        return Attribute::get(fn () => filled($this->password));
    }

    /**
     * Resolve the stored avatar value to a publicly renderable URL.
     * Remote URLs (e.g. GitHub avatars) are returned as-is; otherwise the
     * value is treated as a path on the "public" disk.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function () {
            $avatar = $this->avatar;

            if (blank($avatar)) {
                return null;
            }

            if (Str::startsWith($avatar, ['http://', 'https://'])) {
                return $avatar;
            }

            return Storage::disk('public')->url($avatar);
        });
    }
}
