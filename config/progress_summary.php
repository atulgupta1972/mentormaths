<?php

return [
    'attach_pdf' => env('PROGRESS_SUMMARY_ATTACH_PDF', true),

    /*
    | WhatsApp auto-send requires a Business API provider (Interakt, Twilio, etc.).
    | Keep "manual" until credentials are configured — weekly job still emails + PDF.
    */
    'whatsapp_driver' => env('PROGRESS_SUMMARY_WHATSAPP_DRIVER', 'manual'),
];
