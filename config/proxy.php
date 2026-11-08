<?php

return [
    // Set to the actual Traefik IP/CIDR(s); do not trust arbitrary client headers.
    'trusted' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))),
];
