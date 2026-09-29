<?php
declare(strict_types=1);

namespace App\Core;

/** Resume lisible d'une chaine User-Agent sans dependance externe. */
final class UserAgentInfo
{
    /** @return array{browser:string,system:string,device:string,icon:string} */
    public static function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return ['browser' => 'Navigateur inconnu', 'system' => 'Systeme inconnu', 'device' => 'Appareil inconnu', 'icon' => 'fas fa-question-circle'];
        }

        $browser = match (true) {
            preg_match('/Edg\/([\d.]+)/i', $ua, $match) === 1 => 'Microsoft Edge ' . $match[1],
            preg_match('/OPR\/([\d.]+)/i', $ua, $match) === 1 => 'Opera ' . $match[1],
            preg_match('/Firefox\/([\d.]+)/i', $ua, $match) === 1 => 'Firefox ' . $match[1],
            preg_match('/Chrome\/([\d.]+)/i', $ua, $match) === 1 => 'Chrome ' . $match[1],
            preg_match('/Version\/([\d.]+).*Safari/i', $ua, $match) === 1 => 'Safari ' . $match[1],
            preg_match('/curl\/([\d.]+)/i', $ua, $match) === 1 => 'cURL ' . $match[1],
            default => 'Navigateur inconnu',
        };

        $system = match (true) {
            str_contains($ua, 'Windows NT 10.0') => 'Windows 10/11',
            str_contains($ua, 'Windows') => 'Windows',
            preg_match('/Android\s+([\d.]+)/i', $ua, $match) === 1 => 'Android ' . $match[1],
            preg_match('/(?:iPhone )?OS ([\d_]+)/i', $ua, $match) === 1 => 'iOS ' . str_replace('_', '.', $match[1]),
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Systeme inconnu',
        };

        $isTablet = preg_match('/iPad|Tablet/i', $ua) === 1;
        $isMobile = !$isTablet && preg_match('/Mobile|iPhone|Android/i', $ua) === 1;
        if ($isTablet) {
            return ['browser' => $browser, 'system' => $system, 'device' => 'Tablette', 'icon' => 'fas fa-tablet-alt'];
        }
        if ($isMobile) {
            return ['browser' => $browser, 'system' => $system, 'device' => 'Telephone', 'icon' => 'fas fa-mobile-alt'];
        }
        return ['browser' => $browser, 'system' => $system, 'device' => 'Ordinateur', 'icon' => 'fas fa-desktop'];
    }
}
