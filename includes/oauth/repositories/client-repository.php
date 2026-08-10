<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables created in includes/oauth/schema.php; WordPress has no API for them. Table names come from $wpdb->prefix plus fixed suffixes - never from input - and every value goes through $wpdb->prepare().

namespace WPPilot\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

if (!defined('ABSPATH')) {
    exit();
}

// @mago-expect lint:single-class-per-file
// ClientEntity and ClientRepository are tightly coupled and intentionally in the same file.
final class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** @param list<string> $uris */
    public function setRedirectUri(array $uris): void
    {
        $this->redirectUri = $uris;
    }

    public function setIsConfidential(bool $v): void
    {
        $this->isConfidential = $v;
    }
}

final class ClientRepository implements ClientRepositoryInterface
{
    /** @param list<string> $redirect_uris */
    // @mago-expect lint:no-boolean-flag-parameter
    public function create(
        string $client_name,
        array $redirect_uris,
        string $registered_by_ip,
        bool $admin_created = false,
    ): string {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $client_id = bin2hex(random_bytes(16));
        $wpdb->insert($wpdb->prefix . 'wppilot_oauth_clients', [
            'client_id' => $client_id,
            'client_name' => $client_name,
            'redirect_uris' => wp_json_encode($redirect_uris),
            'is_confidential' => 0,
            'client_secret_hash' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_used_at' => null,
            'registered_by_ip_hash' => hash('sha256', $registered_by_ip),
            'admin_created' => $admin_created ? 1 : 0,
        ]);
        return $client_id;
    }

    public function getClientEntity(mixed $clientIdentifier): ?ClientEntityInterface
    {
        // League's 8.5 ClientRepositoryInterface declares this parameter untyped, so the
        // signature must widen to mixed; narrow back to string for the query below.
        $clientIdentifier = (string) $clientIdentifier;
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $row = $wpdb->get_row($wpdb->prepare("SELECT client_id, client_name, redirect_uris, is_confidential
                 FROM {$wpdb->prefix}wppilot_oauth_clients
                 WHERE client_id = %s", $clientIdentifier), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $entity = new ClientEntity();
        $entity->setIdentifier($row['client_id']);
        // @mago-expect analysis:mixed-argument
        $entity->setName($row['client_name']);
        /** @var list<string> $uris */
        // @mago-expect analysis:mixed-argument
        $uris = json_decode($row['redirect_uris'], associative: true);
        $entity->setRedirectUri($uris);
        // @mago-expect analysis:mixed-operand
        $entity->setIsConfidential((bool) $row['is_confidential']);
        return $entity;
    }

    public function validateClient(mixed $clientIdentifier, mixed $clientSecret, mixed $grantType): bool
    {
        return $this->getClientEntity($clientIdentifier) !== null;
    }

    public function touchLastUsed(string $client_id): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->update(
            $wpdb->prefix . 'wppilot_oauth_clients',
            ['last_used_at' => gmdate('Y-m-d H:i:s')],
            ['client_id' => $client_id],
        );
    }

    public function revoke(string $client_id): void
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->delete($wpdb->prefix . 'wppilot_oauth_clients', ['client_id' => $client_id]);
    }

    /**
     * @return list<array{client_id: string, client_name: string, created_at: string, last_used_at: string|null}>
     */
    public function list_recent(int $limit = 50): array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $rows = $wpdb->get_results($wpdb->prepare("SELECT client_id, client_name, created_at, last_used_at
                 FROM {$wpdb->prefix}wppilot_oauth_clients
                 ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        // @mago-expect analysis:invalid-return-statement
        return $rows === null ? [] : $rows;
    }

    /**
     * Most recent admin-created client for this client_name, so regenerating from the
     * troubleshooter reuses the row instead of stacking new ones.
     */
    public function find_admin_client_id(string $client_name): ?string
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-invalid-argument
        $id = $wpdb->get_var($wpdb->prepare("SELECT client_id FROM {$wpdb->prefix}wppilot_oauth_clients
                 WHERE admin_created = 1 AND client_name = %s
                 ORDER BY created_at DESC LIMIT 1", $client_name));
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return list<array{client_id: string, client_name: string, created_at: string, last_used_at: string|null}>
     */
    public function list_admin_clients(): array
    {
        // @mago-expect lint:no-global
        global $wpdb;
        /** @var \wpdb $wpdb */
        // Not prepared, and cannot be: the only interpolation is $wpdb->prefix in
        // the table name, and prepare() has no placeholder for an identifier.
        // The statement takes no arguments, so no request data reaches it.
        $rows = $wpdb->get_results("SELECT client_id, client_name, created_at, last_used_at
                 FROM {$wpdb->prefix}wppilot_oauth_clients
                 WHERE admin_created = 1
                 ORDER BY created_at DESC", ARRAY_A);
        // @mago-expect analysis:invalid-return-statement
        return $rows === null ? [] : $rows;
    }
}
