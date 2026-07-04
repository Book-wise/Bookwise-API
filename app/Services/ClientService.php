<?php

namespace App\Services;

use App\Models\Client;

class ClientService
{
    /**
     * Sync a client from WooCommerce billing data.
     * Searches by email (case-insensitive), updates if exists, creates if not.
     */
    public function syncFromWooCommerce(array $billingData): Client
    {
        $email = $billingData['email'];

        $client = Client::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        $data = [
            'first_name' => $billingData['first_name'] ?? '',
            'last_name' => $billingData['last_name'] ?? '',
            'phone' => $billingData['phone'] ?? null,
            'email' => $email,
        ];

        if ($client) {
            $client->update($data);

            return $client;
        }

        $data['active'] = true;

        return Client::create($data);
    }
}
