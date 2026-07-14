<?php
/**
 * Blusiast Member ID — inc/member-id.php
 *
 * Handles all logic for member ID numbers and the printable ID card with QR code.
 *
 *  - blusiast_get_member_number( $bl_member_id )  → "BLS-000042"
 *  - blusiast_can_see_member_id( $bl_member )     → bool  (own account OR WP admin)
 *  - blusiast_member_id_card_html( $bl_member )   → HTML string for the card block
 *  - AJAX: blusiast_ajax_member_id_card           → returns card HTML for JS injection
 *
 * Load from functions.php:
 *   require_once BLUSIAST_DIR . '/inc/member-id.php';
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ─────────────────────────────────────────
// 1. HELPERS
// ─────────────────────────────────────────

/**
 * Generate a unique random member number: BLS-XXXXXXXX (8 uppercase alphanumeric chars).
 * Guaranteed unique — retries on collision.
 *
 * @return string  e.g. "BLS-A7X2K9QR"
 */
function blusiast_generate_member_number() {
    global $wpdb;
    $table = $wpdb->prefix . 'bl_members';
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid confusion
    do {
        $code = '';
        for ( $i = 0; $i < 8; $i++ ) {
            $code .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
        }
        $number = 'BLS-' . $code;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE member_number = %s LIMIT 1", $number
        ) );
    } while ( $exists );

    return $number;
}

/**
 * Get the display member number for a bl_members row.
 * Reads the stored member_number column. If empty (legacy member),
 * generates a random one on the fly and saves it permanently.
 *
 * @param  int|object $raw_id  Either the bl_members.id integer, or the full row object.
 * @return string  e.g. "BLS-A7X2K9QR"
 */
function blusiast_get_member_number( $raw_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bl_members';

    if ( is_object( $raw_id ) ) {
        $member = $raw_id;
    } else {
        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, member_number FROM $table WHERE id = %d LIMIT 1", (int) $raw_id
        ) );
    }

    if ( ! $member ) return 'BLS-UNKNOWN';

    if ( ! empty( $member->member_number ) ) {
        return $member->member_number;
    }

    // Legacy member — generate and permanently save a random number
    $number = blusiast_generate_member_number();
    $wpdb->update( $table, [ 'member_number' => $number ], [ 'id' => $member->id ], [ '%s' ], [ '%d' ] );

    return $number;
}

/**
 * Can the currently-logged-in WordPress user see the member ID for $member?
 *
 * Returns true only when:
 *   (a) the viewer IS the member  (their own account), OR
 *   (b) the viewer has manage_options capability  (WP admin / Blusiast staff)
 *
 * @param  object $member  Row from wp_bl_members
 * @return bool
 */
function blusiast_can_see_member_id( $member ) {
    if ( ! is_user_logged_in() ) return false;
    if ( current_user_can( 'manage_options' ) ) return true;
    return (int) get_current_user_id() === (int) $member->wp_user_id;
}


// ─────────────────────────────────────────
// 2. ID CARD HTML
// ─────────────────────────────────────────

/**
 * Return the full HTML for the member ID card block.
 * Includes the QR code (rendered client-side via qrcode.js),
 * a print button, and a download-as-PNG button.
 *
 * The card is ONLY shown when blusiast_can_see_member_id() is true —
 * callers should gate on that before calling this function.
 *
 * @param  object $member  Row from wp_bl_members (needs id, first_name, last_name, account_status, joined_at)
 * @return string  HTML
 */
function blusiast_member_id_card_html( $member ) {

    $number      = blusiast_get_member_number( $member->id );
    $display     = esc_html( trim( $member->first_name . ' ' . $member->last_name ) );
    $status      = esc_html( ucfirst( $member->account_status ) );
    $since       = esc_html( blusiast_format_eastern( $member->joined_at, 'M Y' ) );
    $number_safe = esc_attr( $number );
    $number_html = esc_html( $number );

    ob_start();
    ?>
    <!-- ── MEMBER ID CARD ── -->
    <div class="portal-card bl-id-card-wrap" id="bl-id-card-section">
        <div class="portal-card__title">
            <span class="portal-card__title-dot"></span>
            Member ID
            <span style="margin-left:auto;font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-1);">Private — visible only to you and admins</span>
        </div>

        <!-- The card — portrait layout: QR top, info bottom -->
        <div class="bl-id-card" id="bl-id-card-printable">
            <!-- Top: QR code -->
            <div class="bl-id-card__qr-wrap">
                <div id="bl-qr-canvas"></div>
                <div class="bl-id-card__qr-label">Scan to verify</div>
            </div>
            <!-- Divider -->
            <div class="bl-id-card__divider"></div>
            <!-- Bottom: info -->
            <div class="bl-id-card__info">
                <div class="bl-id-card__logo">BLUSIAST</div>
                <div class="bl-id-card__name"><?php echo $display; ?></div>
                <div class="bl-id-card__status"><?php echo $status; ?> Member</div>
                <div class="bl-id-card__number"><?php echo $number_html; ?></div>
                <div class="bl-id-card__since">Member since <?php echo $since; ?></div>
            </div>
        </div>

        <!-- Actions -->
        <div class="bl-id-card__actions">
            <button type="button" class="bl-btn bl-btn--ghost bl-btn--sm" onclick="blPrintCard()">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="margin-right:6px;">
                    <rect x="3" y="1" width="10" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/>
                    <rect x="3" y="8" width="10" height="6" rx="1" stroke="currentColor" stroke-width="1.4"/>
                    <path d="M5 10h6M5 12h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                    <path d="M3 8H1V5h14v3h-2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Print Card
            </button>
            <button type="button" class="bl-btn bl-btn--ghost bl-btn--sm" onclick="blDownloadCard()">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="margin-right:6px;">
                    <path d="M8 2v8M5 7l3 3 3-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 12h12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                </svg>
                Save as Image
            </button>
        </div>
    </div>

    <!-- QR + card JS (loads qrcode.js from cdnjs, then html2canvas for PNG export) -->
    <script>
    (function(){
        var MEMBER_NUMBER = <?php echo json_encode( $number ); ?>;

        /* ── 1. Render QR code ── */
        function renderQR() {
            var wrap = document.getElementById('bl-qr-canvas');
            if (!wrap || typeof QRCode === 'undefined') return;
            wrap.innerHTML = '';
            new QRCode(wrap, {
                text:           MEMBER_NUMBER,
                width:          96,
                height:         96,
                colorDark:      '#ffffff',
                colorLight:     '#1a1a1a',
                correctLevel:   QRCode.CorrectLevel.M
            });
        }

        /* ── 2. Load qrcode.js then render ── */
        if (typeof QRCode === 'undefined') {
            var s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
            s.onload = renderQR;
            document.head.appendChild(s);
        } else {
            renderQR();
        }

        /* ── 3. Print ── */
        window.blPrintCard = function() {
            var card = document.getElementById('bl-id-card-printable');
            if (!card) return;
            var w = window.open('', '_blank', 'width=520,height=320');
            w.document.write('<html><head><title>Blusiast Member Card</title><style>');
            w.document.write('body{margin:0;background:#111;display:flex;align-items:center;justify-content:center;min-height:100vh;}');
            w.document.write('.bl-id-card{display:flex;gap:0;background:linear-gradient(135deg,#1a1a1a 0%,#2a0a0a 100%);border:1px solid #c00;border-radius:12px;padding:24px 28px;min-width:400px;max-width:460px;box-sizing:border-box;align-items:center;justify-content:space-between;}');
            w.document.write('.bl-id-card__logo{font-family:Impact,sans-serif;font-size:11px;letter-spacing:.2em;color:#c00;text-transform:uppercase;margin-bottom:8px;}');
            w.document.write('.bl-id-card__name{font-family:Impact,sans-serif;font-size:22px;font-weight:900;text-transform:uppercase;color:#fff;letter-spacing:.05em;line-height:1.1;margin-bottom:6px;}');
            w.document.write('.bl-id-card__status{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:#c00;margin-bottom:12px;font-weight:600;}');
            w.document.write('.bl-id-card__number{font-family:monospace;font-size:16px;font-weight:700;color:#fff;letter-spacing:.12em;margin-bottom:4px;}');
            w.document.write('.bl-id-card__since{font-size:10px;color:#666;}');
            w.document.write('.bl-id-card__qr-wrap{text-align:center;flex-shrink:0;}');
            w.document.write('.bl-id-card__qr-label{font-size:9px;color:#666;margin-top:4px;text-transform:uppercase;letter-spacing:.08em;}');
            w.document.write('</style></head><body>');
            w.document.write(card.outerHTML);
            w.document.write('</body></html>');
            w.document.close();
            w.onload = function(){ w.print(); };
        };

        /* ── 4. Save as PNG — canvas-drawn card (avoids cross-origin taint) ── */
        window.blDownloadCard = function() {
            var qrWrap   = document.getElementById('bl-qr-canvas');
            var qrCanvas = qrWrap ? qrWrap.querySelector('canvas') : null;

            // Portrait card dimensions (2x for retina)
            var W = 640, H = 900, PAD = 48, R = 28;

            var out = document.createElement('canvas');
            out.width  = W;
            out.height = H;
            var ctx = out.getContext('2d');

            // ── Background gradient (top-left to bottom-right)
            var grad = ctx.createLinearGradient(0, 0, W, H);
            grad.addColorStop(0, '#1a1a1a');
            grad.addColorStop(1, '#2a0a0a');
            ctx.beginPath();
            ctx.moveTo(R, 0); ctx.lineTo(W-R, 0); ctx.quadraticCurveTo(W, 0, W, R);
            ctx.lineTo(W, H-R); ctx.quadraticCurveTo(W, H, W-R, H);
            ctx.lineTo(R, H);   ctx.quadraticCurveTo(0, H, 0, H-R);
            ctx.lineTo(0, R);   ctx.quadraticCurveTo(0, 0, R, 0);
            ctx.closePath();
            ctx.fillStyle = grad;
            ctx.fill();

            // ── Red border
            ctx.beginPath();
            ctx.moveTo(R, 0); ctx.lineTo(W-R, 0); ctx.quadraticCurveTo(W, 0, W, R);
            ctx.lineTo(W, H-R); ctx.quadraticCurveTo(W, H, W-R, H);
            ctx.lineTo(R, H);   ctx.quadraticCurveTo(0, H, 0, H-R);
            ctx.lineTo(0, R);   ctx.quadraticCurveTo(0, 0, R, 0);
            ctx.closePath();
            ctx.strokeStyle = '#cc0000';
            ctx.lineWidth = 3;
            ctx.stroke();

            // ── Dark QR zone background
            ctx.fillStyle = 'rgba(0,0,0,0.25)';
            ctx.beginPath();
            ctx.moveTo(R, 0); ctx.lineTo(W-R, 0); ctx.quadraticCurveTo(W, 0, W, R);
            ctx.lineTo(W, 480); ctx.lineTo(0, 480); ctx.lineTo(0, R);
            ctx.quadraticCurveTo(0, 0, R, 0);
            ctx.closePath();
            ctx.fill();

            // ── Watermark
            ctx.save();
            ctx.globalAlpha = 0.04;
            ctx.translate(W/2, H/2);
            ctx.rotate(-0.3);
            ctx.font = 'bold 20px Impact, Arial';
            ctx.fillStyle = '#cc0000';
            ctx.textAlign = 'center';
            for (var yi = -3; yi <= 3; yi++) {
                ctx.fillText('BLUSIAST  BLUSIAST  BLUSIAST', 0, yi * 60);
            }
            ctx.restore();
            ctx.globalAlpha = 1;

            // ── QR zone: centered QR code
            function drawQR() {
                var QR_SIZE = 320;
                var qx = (W - QR_SIZE) / 2;
                var qy = 60;

                if (qrCanvas) {
                    ctx.drawImage(qrCanvas, qx, qy, QR_SIZE, QR_SIZE);
                } else {
                    ctx.fillStyle = '#333';
                    ctx.fillRect(qx, qy, QR_SIZE, QR_SIZE);
                }

                // "Scan to verify" below QR
                ctx.font = '400 22px Arial';
                ctx.fillStyle = '#666666';
                ctx.textAlign = 'center';
                ctx.fillText('SCAN TO VERIFY', W/2, qy + QR_SIZE + 36);

                // ── Red divider stripe
                var divY = 490;
                var divGrad = ctx.createLinearGradient(0, 0, W, 0);
                divGrad.addColorStop(0, 'transparent');
                divGrad.addColorStop(0.5, '#cc0000');
                divGrad.addColorStop(1, 'transparent');
                ctx.fillStyle = divGrad;
                ctx.fillRect(0, divY, W, 2);

                // ── Info section (centered)
                ctx.textAlign = 'center';
                var iy = divY + 52;

                // BLUSIAST label
                ctx.font = '700 20px Impact, Arial';
                ctx.fillStyle = '#cc0000';
                ctx.fillText('BLUSIAST', W/2, iy);
                iy += 52;

                // Member name
                var nameText = document.querySelector('.bl-id-card__name') ? document.querySelector('.bl-id-card__name').textContent.trim() : '';
                ctx.font = '900 56px Impact, Arial';
                ctx.fillStyle = '#ffffff';
                // Handle long names — split at space if too wide
                var maxW = W - PAD * 2;
                if (ctx.measureText(nameText).width > maxW) {
                    var parts = nameText.split(' ');
                    var half = Math.ceil(parts.length / 2);
                    ctx.fillText(parts.slice(0, half).join(' '), W/2, iy);
                    iy += 60;
                    ctx.fillText(parts.slice(half).join(' '), W/2, iy);
                    iy += 24;
                } else {
                    ctx.fillText(nameText, W/2, iy);
                    iy += 24;
                }
                iy += 16;

                // Status
                var statusText = document.querySelector('.bl-id-card__status') ? document.querySelector('.bl-id-card__status').textContent.trim() : 'Member';
                ctx.font = '700 22px Arial';
                ctx.fillStyle = '#cc0000';
                ctx.fillText(statusText.toUpperCase(), W/2, iy);
                iy += 52;

                // Member number
                ctx.font = '700 36px "Courier New", monospace';
                ctx.fillStyle = '#ffffff';
                ctx.fillText(MEMBER_NUMBER, W/2, iy);
                iy += 40;

                // Since
                var sinceText = document.querySelector('.bl-id-card__since') ? document.querySelector('.bl-id-card__since').textContent.trim() : '';
                ctx.font = '400 22px Arial';
                ctx.fillStyle = '#666666';
                ctx.fillText(sinceText, W/2, iy);

                // ── Download
                var a = document.createElement('a');
                a.href = out.toDataURL('image/png');
                a.download = MEMBER_NUMBER + '-blusiast-card.png';
                a.click();
            }

            // qrcode.js renders into a <canvas> — if it's ready, use it
            if (qrCanvas) {
                drawQR();
            } else {
                // QR not rendered yet — render it first then capture
                if (typeof QRCode !== 'undefined') {
                    var tmp = document.createElement('div');
                    new QRCode(tmp, {
                        text: MEMBER_NUMBER, width: 200, height: 200,
                        colorDark: '#ffffff', colorLight: '#1a1a1a',
                        correctLevel: QRCode.CorrectLevel.M
                    });
                    setTimeout(function(){
                        qrCanvas = tmp.querySelector('canvas');
                        drawQR();
                    }, 300);
                } else {
                    drawQR();
                }
            }
        };
    })();
    </script>
    <?php
    return ob_get_clean();
}


// ─────────────────────────────────────────
// 3. CSS  (enqueued once per page)
// ─────────────────────────────────────────

add_action( 'wp_head', function() {
    // Only output on pages that are likely to show the card
    if ( ! is_user_logged_in() ) return;
    ?>
    <style id="bl-member-id-styles">

    /* ── ID card wrapper — centers the portrait card ── */
    .bl-id-card-wrap {}

    /* ── The card itself — portrait / mobile-first ── */
    .bl-id-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0;
        background: linear-gradient(160deg, #1a1a1a 0%, #2a0a0a 100%);
        border: 1px solid var(--red, #cc0000);
        border-radius: var(--radius-lg, 14px);
        padding: 0;
        max-width: 320px;
        width: 100%;
        box-sizing: border-box;
        position: relative;
        overflow: hidden;
    }

    /* Subtle watermark */
    .bl-id-card::before {
        content: 'BLUSIAST BLUSIAST BLUSIAST BLUSIAST';
        position: absolute;
        top: 40%;
        left: -20px;
        right: -20px;
        transform: rotate(-12deg);
        font-family: var(--font-display, Impact, sans-serif);
        font-size: 11px;
        letter-spacing: .3em;
        color: rgba(200,0,0,.04);
        white-space: nowrap;
        pointer-events: none;
        user-select: none;
    }

    /* Top section: QR code */
    .bl-id-card__qr-wrap {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 28px 28px 20px;
        background: rgba(0,0,0,.25);
    }

    .bl-id-card__qr-wrap img,
    .bl-id-card__qr-wrap canvas {
        width: 160px !important;
        height: 160px !important;
        display: block;
        border-radius: 6px;
    }

    .bl-id-card__qr-label {
        font-size: 9px;
        color: #666;
        margin-top: 8px;
        text-transform: uppercase;
        letter-spacing: .1em;
    }

    /* Divider with BLUSIAST brand stripe */
    .bl-id-card__divider {
        width: 100%;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--red, #cc0000), transparent);
        opacity: .7;
    }

    /* Bottom section: member info */
    .bl-id-card__info {
        width: 100%;
        padding: 20px 24px 24px;
        box-sizing: border-box;
        text-align: center;
    }

    .bl-id-card__logo {
        font-family: var(--font-display, Impact, sans-serif);
        font-size: 10px;
        letter-spacing: .3em;
        color: var(--red, #cc0000);
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .bl-id-card__name {
        font-family: var(--font-display, Impact, sans-serif);
        font-size: 26px;
        font-weight: 900;
        text-transform: uppercase;
        color: #ffffff;
        letter-spacing: .04em;
        line-height: 1.1;
        margin-bottom: 6px;
        word-break: break-word;
    }

    .bl-id-card__status {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .16em;
        color: var(--red, #cc0000);
        font-weight: 700;
        margin-bottom: 14px;
    }

    .bl-id-card__number {
        font-family: 'Courier New', Courier, monospace;
        font-size: 18px;
        font-weight: 700;
        color: #ffffff;
        letter-spacing: .14em;
        margin-bottom: 6px;
    }

    .bl-id-card__since {
        font-size: 10px;
        color: #666;
        letter-spacing: .06em;
    }

    /* Action buttons row */
    .bl-id-card__actions {
        display: flex;
        gap: 10px;
        margin-top: 16px;
        flex-wrap: wrap;
    }

    /* Print: hide everything except the card */
    @media print {
        body > *:not(#bl-id-card-printable) { display: none !important; }
        #bl-id-card-printable {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            page-break-inside: avoid;
        }
    }

    </style>
    <?php
}, 20 );


// ─────────────────────────────────────────
// 4. ADMIN COLUMN  (wp_bl_members table)
//    Shows formatted member number in the
//    Blusiast Member CRM list view.
// ─────────────────────────────────────────

// Hook into the existing admin member list (output by member-cms.php).
// We filter the rendered table rows via a custom action that member-cms.php
// fires after it outputs each member row.  If that hook doesn't exist yet,
// we fall back to a simple wp_footer injection into admin screens.

add_action( 'admin_head', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    // Inject a tiny script that adds the Member ID column to the CRM table
    // if the table has a "Status" column header we can insert after.
    ?>
    <style>
    .bl-member-number-badge {
        font-family: 'Courier New', Courier, monospace;
        font-size: 11px;
        background: #1a1a1a;
        color: #cc0000;
        border: 1px solid #cc0000;
        border-radius: 4px;
        padding: 2px 7px;
        white-space: nowrap;
        letter-spacing: .08em;
    }
    </style>
    <?php
} );


// ─────────────────────────────────────────
// 5. ADD TO HOME SCREEN — mobile shortcut
// ─────────────────────────────────────────

/**
 * REST endpoint: /blusiast/v1/member-card
 * Returns the logged-in member's card data as JSON (for the PWA shortcut).
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'blusiast/v1', '/member-card', [
        'methods'             => 'GET',
        'callback'            => 'blusiast_rest_member_card',
        'permission_callback' => 'is_user_logged_in',
    ] );
} );

function blusiast_rest_member_card() {
    $member = blusiast_get_current_member();
    if ( ! $member ) {
        return new WP_REST_Response( [ 'error' => 'Member not found.' ], 404 );
    }
    return new WP_REST_Response( [
        'name'         => trim( $member->first_name . ' ' . $member->last_name ),
        'member_number' => blusiast_get_member_number( $member ),
        'status'       => ucfirst( $member->account_status ),
        'since'        => blusiast_format_eastern( $member->joined_at, 'M Y' ),
    ], 200 );
}

/**
 * Outputs the "Add to Home Screen" prompt block.
 * Call inside the member portal ID card section.
 */
function blusiast_add_to_homescreen_html( $member ) {
    $card_url    = blusiast_portal_url( 'id-card' );
    $member_num  = blusiast_get_member_number( $member );
    ob_start();
    ?>
    <div class="bl-homescreen-prompt" id="bl-homescreen-prompt">
        <div class="bl-homescreen-prompt__icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#cc0000" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18.01"/>
            </svg>
        </div>
        <div class="bl-homescreen-prompt__text">
            <strong>Save your card to your phone</strong>
            <span>Add Blusiast to your home screen for one-tap access to your QR code at events.</span>
        </div>
        <button class="bl-btn bl-btn--primary bl-btn--sm" id="bl-homescreen-btn" style="flex-shrink:0;">Add to Home Screen</button>
    </div>

    <!-- iOS instructions (shown when Add to Home Screen API is unavailable — i.e. Safari iOS) -->
    <div class="bl-ios-instructions" id="bl-ios-instructions" hidden>
        <p style="margin:0 0 8px;font-weight:600;color:#fff;">To add to your iPhone home screen:</p>
        <ol style="margin:0;padding-left:18px;color:#ccc;font-size:13px;line-height:1.7;">
            <li>Tap the <strong>Share</strong> button <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> at the bottom of Safari</li>
            <li>Scroll down and tap <strong>"Add to Home Screen"</strong></li>
            <li>Tap <strong>Add</strong> — done!</li>
        </ol>
        <p style="margin:8px 0 0;font-size:12px;color:#666;">The card page will open instantly next time.</p>
    </div>

    <script>
    (function() {
        var prompt  = document.getElementById('bl-homescreen-prompt');
        var iosInst = document.getElementById('bl-ios-instructions');
        var btn     = document.getElementById('bl-homescreen-btn');
        var cardUrl = <?php echo json_encode( $card_url ); ?>;
        var deferredPrompt = null;

        // Chrome / Android — intercept native install prompt
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;
            if (prompt) prompt.hidden = false;
        });

        // Already installed — hide the prompt
        window.addEventListener('appinstalled', function() {
            if (prompt) prompt.hidden = true;
        });

        if (btn) {
            btn.addEventListener('click', function() {
                var isIOS    = /iphone|ipad|ipod/i.test(navigator.userAgent);
                var isSafari = /safari/i.test(navigator.userAgent) && !/chrome/i.test(navigator.userAgent);

                if (deferredPrompt) {
                    // Android Chrome native prompt
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then(function(result) {
                        deferredPrompt = null;
                        if (prompt) prompt.hidden = true;
                    });
                } else if (isIOS && isSafari) {
                    // iOS Safari — show manual instructions
                    if (prompt)  prompt.hidden  = true;
                    if (iosInst) iosInst.hidden = false;
                } else {
                    // Fallback — open card page and guide user
                    if (prompt)  prompt.hidden  = true;
                    if (iosInst) iosInst.hidden = false;
                }
            });
        }

        // Hide prompt if already running as standalone (already installed)
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
            if (prompt) prompt.hidden = true;
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
