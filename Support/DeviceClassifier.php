<?php

declare(strict_types=1);

namespace App\Plugins\IdfDashboard\Support;

final class DeviceClassifier
{
    public const CATEGORIES = [
        'Network',
        'Server',
        'Power',
        'Wireless',
        'Security',
        'POS',
        'Printer',
        'Camera',
        'Controller',
        'Other',
    ];

    /**
     * Precedence resolves conflicting signals deterministically. Specific
     * infrastructure identities precede broad LibreNMS types; uncertain
     * text never turns a generic host name into a server.
     *
     * @param  array<string, mixed>  $device
     * @param  iterable<int, string>  $sensorClasses
     * @return array{category: string, reason: string, confidence: string, signals: array<int, string>}
     */
    public static function classify(array $device, iterable $sensorClasses = []): array
    {
        $fields = [];

        foreach (['type', 'os', 'hardware', 'sysDescr', 'purpose', 'hostname', 'display'] as $field) {
            $value = strtolower(trim((string) ($device[$field] ?? '')));

            if ($value !== '') {
                $fields[$field] = $value;
            }
        }

        $type = $fields['type'] ?? '';
        $os = $fields['os'] ?? '';
        $identity = implode(' ', $fields);
        $classes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $class): string => strtolower(trim((string) $class)),
            is_array($sensorClasses) ? $sensorClasses : iterator_to_array($sensorClasses)
        ))));

        $rules = [
            ['Power', 'high', self::matches($identity, ['ups', 'pdu', 'power distribution unit', 'uninterruptible power']) || $type === 'power', 'UPS/PDU or LibreNMS power type'],
            ['Security', 'high', in_array($type, ['firewall', 'security'], true) || self::matches($os, ['fortios', 'panos', 'paloalto', 'checkpoint', 'sonicwall']), 'Firewall/security platform'],
            ['Wireless', 'high', $type === 'wireless' || self::matches($identity, ['wireless controller', 'access point', 'wifi controller', 'wlan controller']), 'Wireless type or explicit wireless identity'],
            ['Printer', 'high', $type === 'printer' || self::matches($identity, ['printer', 'laserjet', 'officejet', 'multifunction']), 'Printer type or explicit printer identity'],
            ['Camera', 'medium', self::matches($identity, ['camera', 'cctv', 'video surveillance', 'ipcam']), 'Explicit camera/surveillance identity'],
            ['POS', 'medium', self::matches($identity, ['point of sale', 'pos terminal', 'micros workstation', 'oracle micros']), 'Explicit point-of-sale identity'],
            ['Controller', 'medium', self::matches($identity, ['controller', 'control panel', 'building management system', 'bms appliance']), 'Explicit controller identity'],
            ['Server', 'high', $type === 'server', 'LibreNMS server type'],
            ['Network', 'high', $type === 'network' || self::matches($identity, ['ethernet switch', 'network switch', 'router', 'routing platform']), 'LibreNMS network type or explicit routing/switch identity'],
        ];

        foreach ($rules as [$category, $confidence, $matched, $reason]) {
            if ($matched) {
                $signals = self::signals($category, $fields, $classes);

                return [
                    'category' => $category,
                    'reason' => $reason,
                    'confidence' => $confidence,
                    'signals' => $signals !== [] ? $signals : ['type=' . ($type !== '' ? $type : 'unknown')],
                ];
            }
        }

        return [
            'category' => 'Other',
            'reason' => 'No sufficiently specific classification signal',
            'confidence' => 'low',
            'signals' => array_values(array_filter([
                $type !== '' ? 'type=' . $type : null,
                $os !== '' ? 'os=' . $os : null,
                $classes !== [] ? 'sensors=' . implode(',', $classes) : null,
            ])),
        ];
    }

    /** @param array<int, string> $needles */
    private static function matches(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/i', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<int, string>  $classes
     * @return array<int, string>
     */
    private static function signals(string $category, array $fields, array $classes): array
    {
        $signals = [];

        foreach ($fields as $name => $value) {
            if ($name === 'type' || $name === 'os' || self::matches($value, self::categoryTerms($category))) {
                $signals[] = $name . '=' . $value;
            }
        }

        if ($classes !== []) {
            $signals[] = 'sensors=' . implode(',', $classes);
        }

        return array_slice(array_values(array_unique($signals)), 0, 5);
    }

    /** @return array<int, string> */
    private static function categoryTerms(string $category): array
    {
        return match ($category) {
            'Power' => ['ups', 'pdu', 'power distribution unit', 'uninterruptible power'],
            'Security' => ['firewall', 'security'],
            'Wireless' => ['wireless', 'access point', 'wifi', 'wlan'],
            'Printer' => ['printer', 'laserjet', 'officejet', 'multifunction'],
            'Camera' => ['camera', 'cctv', 'video surveillance', 'ipcam'],
            'POS' => ['point of sale', 'pos terminal', 'micros workstation', 'oracle micros'],
            'Controller' => ['controller', 'control panel', 'building management system'],
            'Server' => ['server'],
            'Network' => ['network', 'switch', 'router'],
            default => [],
        };
    }
}
