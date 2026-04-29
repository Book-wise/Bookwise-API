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

        // Build name from first_name + last_name
        $firstName = $data['first_name'] ?? ($billing['first_name'] ?? '');
        $lastName = $data['last_name'] ?? ($billing['last_name'] ?? '');
        $fullName = trim($firstName . ' ' . $lastName);

        // Build address from billing address lines
        $addressParts = [
            $billing['address_1'] ?? '',
            $billing['address_2'] ?? '',
            $billing['city'] ?? '',
            $billing['state'] ?? '',
            $billing['postcode'] ?? '',
            $billing['country'] ?? '',
        ];
        $address = collect($addressParts)->filter()->join(', ');

        // Find by wc_customer_id OR email (unique per WC customer)
        $client = Client::where('wc_customer_id', $customerId)
            ->orWhere('email', $data['email'])
            ->first();

        $isNew = !$client;

        $client = Client::updateOrCreate(
            [
                'wc_customer_id' => $customerId,
            ],
            [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'     => $data['email'],
                'phone'     => $data['billing']['phone'] ?? $data['shipping']['phone'] ?? ($billing['phone'] ?? null),
                'address'   => $address ?: null,
                'active'    => true,
            ]
        );

        // If this was a customer.created event and it's new, log it
        if ($isNew && Str::contains($event, 'created')) {
            // The webhook log is handled by WebhookController
        }

        return $client;
    }
}