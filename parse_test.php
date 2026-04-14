<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tryouts = App\Models\Tryout::take(2)->get();
foreach ($tryouts as $t) {
    echo "ID: " . $t->id . " | Mulai (Raw/DB): " . $t->getRawOriginal('mulai') . " | Mulai (String): " . (string)$t->mulai . " | Format ISO: " . $t->mulai->toISOString() . "\n";
}
