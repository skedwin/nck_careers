<?php

namespace App\Support;

class KenyaCounties
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'Baringo',
            'Bomet',
            'Bungoma',
            'Busia',
            'Elgeyo-Marakwet',
            'Embu',
            'Garissa',
            'Homa Bay',
            'Isiolo',
            'Kajiado',
            'Kakamega',
            'Kericho',
            'Kiambu',
            'Kilifi',
            'Kirinyaga',
            'Kisii',
            'Kisumu',
            'Kitui',
            'Kwale',
            'Laikipia',
            'Lamu',
            'Machakos',
            'Makueni',
            'Mandera',
            'Marsabit',
            'Meru',
            'Migori',
            'Mombasa',
            "Murang'a",
            'Nairobi',
            'Nakuru',
            'Nandi',
            'Narok',
            'Nyamira',
            'Nyandarua',
            'Nyeri',
            'Samburu',
            'Siaya',
            'Taita-Taveta',
            'Tana River',
            'Tharaka-Nithi',
            'Trans Nzoia',
            'Turkana',
            'Uasin Gishu',
            'Vihiga',
            'Wajir',
            'West Pokot',
        ];
    }

    /**
     * Match free text to a known county name, or null if unknown / polluted.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        // Drop phone numbers / job-description blobs pasted into the county field.
        $raw = preg_replace('/[\r\n]+/', ' ', $raw) ?? $raw;
        $raw = preg_replace('/\b(?:tel|mobile|phone|email|p\.?\s*o\.?\s*box)\b.*$/iu', '', $raw) ?? $raw;
        $raw = preg_replace('/[•·▪◦□\x{f0b7}\x{2022}].*$/u', '', $raw) ?? $raw;
        $raw = preg_replace('/\+?\d[\d\s\-()]{6,}.*$/u', '', $raw) ?? $raw;
        $raw = trim($raw, " \t\n\r\0\x0B,;:|/-");
        if ($raw === '') {
            return null;
        }

        // Keep only a short leading place-name token from polluted paste-ins.
        if (mb_strlen($raw) > 40) {
            if (preg_match('/^([A-Za-z][A-Za-z\'\-]{1,20}(?:\s+[A-Za-z][A-Za-z\'\-]{1,20}){0,2})/u', $raw, $m)) {
                $raw = trim($m[1]);
            } else {
                return null;
            }
        }

        foreach (self::all() as $county) {
            if (strcasecmp($raw, $county) === 0) {
                return $county;
            }
        }

        $aliases = [
            'nairobi city' => 'Nairobi',
            'nairobi county' => 'Nairobi',
            'elgeyo marakwet' => 'Elgeyo-Marakwet',
            'elgeyo-marakwet' => 'Elgeyo-Marakwet',
            'taita taveta' => 'Taita-Taveta',
            'taita-taveta' => 'Taita-Taveta',
            'tharaka nithi' => 'Tharaka-Nithi',
            'tharaka-nithi' => 'Tharaka-Nithi',
            'trans-nzoia' => 'Trans Nzoia',
            'trans nzoia' => 'Trans Nzoia',
            'homabay' => 'Homa Bay',
            'homa-bay' => 'Homa Bay',
            'muranga' => "Murang'a",
            "murang'a" => "Murang'a",
            'tana-river' => 'Tana River',
            'tana river' => 'Tana River',
            'uasin-gishu' => 'Uasin Gishu',
            'west-pokot' => 'West Pokot',
        ];

        $lower = strtolower(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        $lower = preg_replace('/\s+county$/u', '', $lower) ?? $lower;

        if (isset($aliases[$lower])) {
            return $aliases[$lower];
        }

        foreach ($aliases as $alias => $county) {
            if ($lower === $alias) {
                return $county;
            }
        }

        // Accept only when the (short) value is essentially the county name.
        if (mb_strlen($raw) <= 40) {
            foreach (self::all() as $county) {
                if (preg_match('/^'.preg_quote($county, '/').'(?:\s+county)?$/iu', $raw)) {
                    return $county;
                }
            }
            foreach (self::all() as $county) {
                if (preg_match('/\b'.preg_quote($county, '/').'\b/iu', $raw)) {
                    // Reject if lots of extra words (e.g. job titles).
                    $words = preg_split('/\s+/u', $raw) ?: [];
                    if (count($words) <= 3) {
                        return $county;
                    }
                }
            }
        }

        return null;
    }
}
