<?php
/**
 * Branded HTML mails (Team Michelhausen CI, inline styles — mail clients strip
 * <style> blocks and know nothing about CSS variables).
 */

function wq_mail_shell($headline, $bodyHtml)
{
    $red  = '#e51324';
    $ink  = '#3f3f3e';
    $soft = '#f4f5f6';

    return '<!DOCTYPE html><html lang="de-AT"><head><meta charset="UTF-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;padding:0;background:' . $soft . ';">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $soft . ';padding:24px 12px;">'
      . '<tr><td align="center">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">'

      . '<tr><td style="height:5px;background:' . $red . ';font-size:0;line-height:0;">&nbsp;</td></tr>'

      . '<tr><td style="padding:32px 32px 8px 32px;">'
      . '<p style="margin:0 0 6px 0;font:600 12px/1.4 Rubik,Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:' . $red . ';">Wirtshausquiz</p>'
      . '<h1 style="margin:0;font:400 26px/1.2 Georgia,serif;color:' . $ink . ';">' . $headline . '</h1>'
      . '</td></tr>'

      . '<tr><td style="padding:8px 32px 28px 32px;font:400 15px/1.6 Rubik,Arial,sans-serif;color:' . $ink . ';">'
      . $bodyHtml
      . '</td></tr>'

      . '<tr><td style="padding:18px 32px 26px 32px;border-top:1px solid #e7e5e3;font:400 12px/1.6 Rubik,Arial,sans-serif;color:#74726f;">'
      . 'SPÖ &amp; Unabhängige – Team Michelhausen<br>'
      . '<a href="https://team-michelhausen.at/impressum/" style="color:#74726f;">Impressum</a> · '
      . '<a href="https://team-michelhausen.at/datenschutz/" style="color:#74726f;">Datenschutz</a>'
      . '</td></tr>'

      . '</table></td></tr></table></body></html>';
}

function wq_button($url, $label)
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0;">'
      . '<tr><td style="background:#e51324;border-radius:999px;">'
      . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
      . 'style="display:inline-block;padding:13px 28px;font:500 15px/1 Rubik,Arial,sans-serif;color:#ffffff;text-decoration:none;">'
      . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>'
      . '</td></tr></table>';
}

/**
 * Confirmation after a successful signup.
 * $slot is CONFIRMED or WAITLIST; $promoRank > 0 means a free round was won.
 */
function wq_confirmation_mail($reg, $slot, $promoRank, $cfg, $cancelUrl)
{
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $dateLine = fmt_date_de($cfg['event_date']);
    $team     = $e($reg['teamName']);
    $name     = $e($reg['captainName']);

    if ($slot === 'WAITLIST') {
        $headline = 'Ihr steht auf der Warteliste';
        $intro = '<p style="margin:0 0 14px 0;">Servus ' . $name . '!</p>'
          . '<p style="margin:0 0 14px 0;">Der Quizabend ist aktuell voll — <strong>' . $team . '</strong> '
          . 'steht auf der Warteliste. Erfahrungsgemäß sagen ein paar Teams noch ab, '
          . 'die Chancen stehen also gut. Wir melden uns, sobald ein Platz frei wird.</p>';
        $subject = 'Warteliste: ' . $reg['teamName'] . ' – Wirtshausquiz';
    } else {
        $headline = 'Ihr seid dabei!';
        $intro = '<p style="margin:0 0 14px 0;">Servus ' . $name . '!</p>'
          . '<p style="margin:0 0 14px 0;"><strong>' . $team . '</strong> ist für das Wirtshausquiz '
          . 'angemeldet. Wir freuen uns auf euch.</p>';
        $subject = 'Angemeldet: ' . $reg['teamName'] . ' – Wirtshausquiz';
    }

    if ($promoRank > 0) {
        $intro .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
          . 'style="margin:0 0 16px 0;background:#fff4d1;border-radius:8px;">'
          . '<tr><td style="padding:14px 16px;font:400 14px/1.5 Rubik,Arial,sans-serif;color:#8a6400;">'
          . '🍺 <strong>Freirunde gesichert.</strong> Ihr wart Team Nummer ' . (int)$promoRank
          . ' — die erste Runde geht an eurem Tisch aufs Haus.'
          . '</td></tr></table>';
    }

    $facts = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
      . 'style="margin:0 0 18px 0;background:#f4f5f6;border-radius:8px;">'
      . '<tr><td style="padding:16px 18px;font:400 14px/1.7 Rubik,Arial,sans-serif;color:#3f3f3e;">'
      . '<strong>' . $e($dateLine) . '</strong><br>'
      . 'Einlass ab ' . $e($cfg['doors_time']) . ' Uhr · Quizstart ' . $e($cfg['start_time']) . ' Uhr<br>'
      . $e($cfg['venue']) . '<br>'
      . $e($cfg['address'])
      . '</td></tr></table>';

    $body = $intro . $facts
      . '<p style="margin:0 0 14px 0;">Angemeldet mit <strong>' . (int)$reg['size'] . ' Personen</strong>. '
      . 'Die Küche hat ab ' . $e($cfg['doors_time']) . ' Uhr offen — kommt ruhig früher und esst vorher etwas, '
      . 'das Quiz startet um ' . $e($cfg['start_time']) . ' Uhr.</p>'
      . '<p style="margin:0 0 6px 0;">Ihr könnt doch nicht? Bitte sagt uns kurz ab, dann rückt ein Team von der '
      . 'Warteliste nach:</p>'
      . wq_button($cancelUrl, 'Anmeldung stornieren')
      . '<p style="margin:0;font-size:13px;color:#74726f;">Diesen Link bitte aufheben — er ist der einzige Weg, '
      . 'eure Anmeldung selbst zu ändern.</p>';

    $text =
        "Servus " . $reg['captainName'] . "!\r\n\r\n"
      . ($slot === 'WAITLIST'
            ? ($reg['teamName'] . " steht auf der Warteliste für das Wirtshausquiz.\r\n\r\n")
            : ($reg['teamName'] . " ist für das Wirtshausquiz angemeldet.\r\n\r\n"))
      . ($promoRank > 0 ? ("Freirunde gesichert: Ihr wart Team Nummer " . $promoRank . ".\r\n\r\n") : '')
      . $dateLine . "\r\n"
      . "Einlass ab " . $cfg['doors_time'] . " Uhr, Quizstart " . $cfg['start_time'] . " Uhr\r\n"
      . $cfg['venue'] . "\r\n" . $cfg['address'] . "\r\n\r\n"
      . "Angemeldet mit " . (int)$reg['size'] . " Personen.\r\n\r\n"
      . "Absagen: " . $cancelUrl . "\r\n\r\n"
      . "Team Michelhausen\r\n";

    return [
        'subject' => $subject,
        'html'    => wq_mail_shell($headline, $body),
        'text'    => $text,
    ];
}
