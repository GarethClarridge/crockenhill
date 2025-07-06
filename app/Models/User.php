<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
// use Illuminate\Support\Facades\Hash; // No longer directly used in this file
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Carbon; // For type hinting Carbon instances

/**
 * App\Models\User
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?Carbon $email_verified_at
 * @property string $password
 * @property bool $is_admin
 * @property ?string $remember_token
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection|\Illuminate\Notifications\DatabaseNotification[] $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\Laravel\Sanctum\PersonalAccessToken[] $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory(...$parameters)
 * @method static \Illuminate\Database\Eloquent\Builder|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|User query()
 * @mixin \Eloquent
 */
class User extends Authenticatable implements MustVerifyEmail
{
  use HasFactory, Notifiable, HasApiTokens;

  /**
   * The database table used by the model.
   */
  protected $table = 'users';

  /**
   * The attributes that are mass assignable.
   *
   * @var list<string>
   */
  protected $fillable = [
      'name',
      'email',
      'password',
      'is_admin', // Added is_admin to fillable if it can be mass assigned
  ];

  /**
   * The attributes excluded from the model's JSON form.
   *
   * @var list<string>
   */
  protected $hidden = [
      'password',
      'remember_token',
  ];

  /**
   * The attributes that should be cast.
   *
   * @var array<string, string>
   */
  protected $casts = [
      'email_verified_at' => 'datetime',
      'is_admin' => 'boolean',
      'password' => 'hashed', // Recommended for Laravel 10+ for automatic hashing
  ];
}
