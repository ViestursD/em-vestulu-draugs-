<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "✅ PHP WORKS!\n";

// Pārbauda failus
$files = [
    '.env.php',
    'utils/db.php',
    'utils/buves_grupa.php',
    'utils/decision.php',
    'chat_send.php'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        echo "✅ $f EXISTS\n";
    } else {
        echo "❌ $f MISSING!\n";
    }
}

// Pārbauda require
try {
    echo "\n--- Testing requires ---\n";
    
    @require_once '.env.php';
    echo "✅ .env.php loaded\n";
    
    @require_once 'utils/db.php';
    echo "✅ db.php loaded\n";
    
    @require_once 'utils/buves_grupa.php';
    echo "✅ buves_grupa.php loaded\n";
    
    if (function_exists('determine_objekts_variant')) {
        echo "✅ determine_objekts_variant() exists\n";
    } else {
        echo "❌ determine_objekts_variant() NOT FOUND\n";
    }
    
    @require_once 'utils/decision.php';
    echo "✅ decision.php loaded\n";
    
    // Test DB
    if (function_exists('db')) {
        $pdo = @db();
        echo "✅ DB connected\n";
    } else {
        echo "❌ db() function not found\n";
    }
    
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n✅ DEBUG COMPLETE\n";
?>
