<?php

namespace App\Support;

/**
 * Minimal Safe Exam Browser .seb config (plist XML), port of seb-config.ts.
 */
class Seb
{
    public static function buildConfigXml(string $examName, string $examUrl, string $hashKey): string
    {
        $safeName = self::escapeXml($examName);
        $safeUrl = self::escapeXml($examUrl);
        $safeKey = self::escapeXml($hashKey);

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>originatorVersion</key>
  <string>Exam Dashboard</string>
  <key>startURL</key>
  <string>{$safeUrl}</string>
  <key>hashedAdminPassword</key>
  <string></string>
  <key>hashedQuitPassword</key>
  <string></string>
  <key>browserExamKey</key>
  <string>{$safeKey}</string>
  <key>sendBrowserExamKey</key>
  <true/>
  <key>quitURL</key>
  <string></string>
  <key>enableQuitURL</key>
  <true/>
  <key>browserViewMode</key>
  <integer>0</integer>
  <key>browserWindowAllowReload</key>
  <true/>
  <key>browserWindowShowURL</key>
  <integer>0</integer>
  <key>copyToClipboard</key>
  <false/>
  <key>cutToClipboard</key>
  <false/>
  <key>pasteFromClipboard</key>
  <false/>
  <key>enableF12</key>
  <false/>
  <key>enablePrintScreen</key>
  <false/>
  <key>showTaskBar</key>
  <false/>
  <key>showTime</key>
  <true/>
  <key>showReloadButton</key>
  <true/>
  <key>title</key>
  <string>{$safeName}</string>
</dict>
</plist>

XML;
    }

    public static function slugify(string $value): string
    {
        $s = mb_strtolower($value);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
        $s = trim($s, '_');
        $s = substr($s, 0, 40);

        return $s !== '' ? $s : 'exam';
    }

    private static function escapeXml(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $value
        );
    }
}
