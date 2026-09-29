<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

use Magento\Framework\DataObject;
use ReturnSentry\Connector\Model\Config;

class Identity
{
    public const PSEUDONYMOUS_EMAIL_DOMAIN = 'pseudo.invalid';

    public function __construct(private readonly Config $config)
    {
    }

    public function customerExternalId(int $customerId): string
    {
        return 'm2:' . $customerId;
    }

    public function isPseudonymized(int|string|null $storeId): bool
    {
        return $this->config->pseudonymSalt($storeId) !== null;
    }

    /**
     * @return array<string, string>
     */
    public function customer(?int $customerId, ?string $email, ?string $phone, int|string|null $storeId): array
    {
        $salt = $this->config->pseudonymSalt($storeId);
        $email = Fields::clip($email, 320);
        $phone = Fields::clip($phone, 40);
        return Fields::compact([
            'externalId' => $customerId ? $this->customerExternalId($customerId) : null,
            'email' => $email === null || $salt === null ? $email : $this->pseudonymousEmail($email, $salt),
            'phone' => $salt === null && mb_strlen((string) $phone) >= 4 ? $phone : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function address(?DataObject $address, int|string|null $storeId): array
    {
        $country = strtoupper((string) $address?->getData('country_id'));
        if ($address === null || strlen($country) !== 2) {
            return [];
        }
        $street = (string) $address->getData('street');
        $postcode = (string) $address->getData('postcode');
        $salt = $this->config->pseudonymSalt($storeId);
        if ($salt !== null) {
            return Fields::compact([
                'shippingAddress' => ['country' => $country],
                'addressFingerprint' => $this->addressFingerprint($country, $postcode, $street, $salt),
                'phoneFingerprint' => $this->phoneFingerprint((string) $address->getData('telephone'), $salt),
            ]);
        }
        $lines = explode("\n", $street);
        return [
            'shippingAddress' => Fields::compact([
                'line1' => Fields::clip(array_shift($lines), 300),
                'line2' => Fields::clip(implode(' ', $lines), 300),
                'postalCode' => Fields::clip($postcode, 20),
                'city' => Fields::clip((string) $address->getData('city'), 120),
                'region' => Fields::clip((string) $address->getData('region'), 120),
                'country' => $country,
            ]),
        ];
    }

    public function pseudonymousEmail(string $email, string $salt): string
    {
        $token = hash('sha256', $salt . mb_strtolower(trim($email, ' ')));
        return substr($token, 0, 40) . '@' . self::PSEUDONYMOUS_EMAIL_DOMAIN;
    }

    public function addressFingerprint(string $country, string $postcode, string $street, string $salt): ?string
    {
        if (trim($street) === '') {
            return null;
        }
        $postcode = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($postcode));
        $street = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($street));
        return strtoupper(hash('sha256', $salt . 'address:' . strtoupper($country) . '|' . $postcode . '|' . $street));
    }

    public function phoneFingerprint(string $phone, string $salt): ?string
    {
        $digits = (string) preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) < 8) {
            return null;
        }
        return strtoupper(hash('sha256', $salt . 'phone:' . substr($digits, -9)));
    }
}
