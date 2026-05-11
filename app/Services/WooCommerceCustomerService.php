<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Str;

class WooCommerceCustomerService
{
    /**
     * Sync a WooCommerce customer to the Client model.
     * Client remains STANDALONE - no link to User model.
     */
    public function syncCustomer(array $data, string $event): Client
    {
        $billing = $data['billing'] ?? [];
        $customerId = $data['id'] ?? null;
        $metaData = $data['meta_data'] ?? [];

        $firstName = $data['first_name'] ?? ($billing['first_name'] ?? '');
        $lastName = $data['last_name'] ?? ($billing['last_name'] ?? '');

        $addressParts = [
            $billing['address_1'] ?? '',
            $billing['address_2'] ?? '',
            $billing['city'] ?? '',
            $billing['state'] ?? '',
            $billing['postcode'] ?? '',
            $billing['country'] ?? '',
        ];
        $address = collect($addressParts)->filter()->join(', ');

        $rut = null;
        if (!empty($metaData)) {
            $rutMeta = collect($metaData)->firstWhere('key', '_billing_rut');
            $rut = $rutMeta['value'] ?? null;
        }

        $client = Client::where('wc_customer_id', $customerId)
            ->orWhere('email', $data['email'])
            ->first();

        $isNew = !$client;

        $updateData = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'     => $data['email'],
            'phone'     => $data['billing']['phone'] ?? $data['shipping']['phone'] ?? ($billing['phone'] ?? null),
            'address'   => $address ?: null,
            'active'    => true,
        ];

        if ($rut) {
            $updateData['rut'] = $rut;
        }

        $client = Client::updateOrCreate(
            [
                'wc_customer_id' => $customerId,
            ],
            $updateData
        );

        if ($isNew && Str::contains($event, 'created')) {
        }

        return $client;
    }
}