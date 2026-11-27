<?php
declare(strict_types=1);

use WHCSystem\Module\ApiError;

/** Authorize the active WHMCS user for the currently selected client account. */
function whcsystem_client_identity(bool $manage): int
{
    if (!class_exists(\WHMCS\Authentication\CurrentUser::class)) throw new ApiError(403,'PLATFORM_AUTHORIZATION_UNAVAILABLE');
    $actor = new \WHMCS\Authentication\CurrentUser();
    $client = $actor->client();
    $user = $actor->user();
    if (!$actor->isAuthenticatedUser() || !$client || !$user) throw new ApiError(401,'AUTHENTICATION_FAILED');
    if ($client->isOwnedBy($user)) return (int)$client->id;
    $result = localAPI('GetUserPermissions',['user_id'=>(int)$user->id,'client_id'=>(int)$client->id]);
    $permissions = $result['permissions']['permission'] ?? $result['permissions'] ?? [];
    if (is_string($permissions)) $permissions = explode(',',$permissions);
    $required = $manage ? 'manageproducts' : 'products';
    if (($result['result'] ?? '') !== 'success' || !is_array($permissions) || !in_array($required,$permissions,true))
        throw new ApiError(403,'AUTHORIZATION_DENIED');
    return (int)$client->id;
}