<?php

require __DIR__.'/inventory-whatsapp-browser-bootstrap.php';
for ($count = 0; $count < 100; $count++) {
    $job = app('queue')->connection('database')->pop('whatsapp');
    if (! $job) {
        break;
    }
    $job->fire();
}
echo "Processed {$count} isolated WhatsApp jobs using HTTP fakes.\n";
