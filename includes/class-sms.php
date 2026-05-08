<?php
namespace CB\Core;

defined( 'ABSPATH' ) || exit;

/**
 * SSL Wireless Push SMS API v3 integration.
 *
 * Configure credentials under Course Builder → Settings:
 *   - API Domain  : provided by SSL Wireless (e.g. https://sms.sslwireless.com)
 *   - API Token   : alphanumeric, up to 50 chars
 *   - Sender ID   : brand masking name provided by SSL, up to 20 chars
 */
class SMS {

    /**
     * Send an OTP via SSL Wireless Single SMS API (POST /api/v3/send-sms).
     *
     * @param string $phone       Recipient MSISDN, e.g. 8801XXXXXXXXX (16 digits max).
     * @param string $otp         6-digit OTP code.
     * @param string $course_name Course title used in the SMS body.
     * @return bool               True when the API returns status "SUCCESS" and
     *                            smsinfo[0].sms_status "SUCCESS".
     */
    public static function send_otp( string $phone, string $otp, string $course_name ): bool {
        $api_token = get_option( 'cb_sslwireless_api_token', '' );
        $sid       = get_option( 'cb_sslwireless_sid', '' );
        $domain    = rtrim( get_option( 'cb_sslwireless_domain', 'https://smsplus.sslwireless.com' ), '/' );

        if ( empty( $api_token ) || empty( $sid ) ) {
            error_log( '[CB SMS] API token or SID not configured. Set them under Course Builder → Settings.' );
            return false;
        }

        $message = sprintf(
            '"%s" ডেমো ক্লাসের জন্য আপনার OTP হলো: %s। এটি ১০ মিনিটের জন্য বৈধ। কারো সাথে শেয়ার করবেন না।',
            $course_name,
            $otp
        );

        // csms_id must be unique per day, max 20 chars.
        // Format: CB + last-7 digits of timestamp + 3-digit rand = 2+7+3 = 12 chars.
        $csms_id = 'CB' . substr( (string) time(), -7 ) . wp_rand( 100, 999 );

        $payload = [
            'api_token' => $api_token,
            'sid'       => $sid,
            'msisdn'    => $phone,
            'sms'       => $message,
            'csms_id'   => $csms_id,
        ];

        $response = wp_remote_post(
            $domain . '/api/v3/send-sms',
            [
                'timeout' => 15,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => json_encode( $payload ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[CB SMS] HTTP error: ' . $response->get_error_message() );
            return false;
        }

        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        // Top-level status must be "SUCCESS" (docs §4.1.3).
        if ( ! isset( $body['status'] ) || strtoupper( $body['status'] ) !== 'SUCCESS' ) {
            $err = $body['error_message'] ?? $raw;
            error_log( '[CB SMS] API returned non-SUCCESS. status_code=' . ( $body['status_code'] ?? '?' ) . ' error=' . $err );
            return false;
        }

        // Per-SMS status in smsinfo[0].sms_status must also be "SUCCESS" (docs §3.2).
        $sms_status = strtoupper( $body['smsinfo'][0]['sms_status'] ?? '' );
        if ( $sms_status !== 'SUCCESS' ) {
            $msg = $body['smsinfo'][0]['status_message'] ?? 'unknown';
            error_log( '[CB SMS] smsinfo[0].sms_status=' . $sms_status . ' (' . $msg . ')' );
            return false;
        }

        return true;
    }
}
