<?php
/**
 * Script to generate PNG logo from SVG for email usage
 * 
 * This script converts the SVG logo to PNG format for better email client compatibility.
 * 
 * Requirements:
 * - ImageMagick or GD extension
 * - SVG logo file at assets/images/logo.svg
 * 
 * Usage:
 *   php cli/generate-logo-png.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$svgPath = __DIR__ . '/../assets/images/logo.svg';
$pngPath = __DIR__ . '/../assets/images/logo.png';

if (!file_exists($svgPath)) {
    echo "Error: SVG logo not found at {$svgPath}\n";
    exit(1);
}

echo "Converting SVG to PNG...\n";

// Try ImageMagick first (better SVG support)
if (extension_loaded('imagick')) {
    try {
        $imagick = new Imagick();
        $imagick->setBackgroundColor(new ImagickPixel('transparent'));
        $imagick->readImage($svgPath);
        $imagick->setImageFormat('png');
        
        // Set size (width 400px, maintain aspect ratio)
        $imagick->resizeImage(400, 0, Imagick::FILTER_LANCZOS, 1, true);
        
        // Save PNG
        $imagick->writeImage($pngPath);
        $imagick->clear();
        $imagick->destroy();
        
        echo "✓ PNG logo created successfully using ImageMagick at {$pngPath}\n";
        echo "  Size: " . filesize($pngPath) . " bytes\n";
        exit(0);
    } catch (Exception $e) {
        echo "ImageMagick error: " . $e->getMessage() . "\n";
        echo "Trying alternative method...\n";
    }
}

// Alternative: Use Inkscape if available (command line)
$inkscapePath = null;
$possiblePaths = [
    'inkscape',
    '/usr/bin/inkscape',
    '/usr/local/bin/inkscape',
    'C:\\Program Files\\Inkscape\\bin\\inkscape.exe',
    'C:\\Program Files (x86)\\Inkscape\\bin\\inkscape.exe'
];

foreach ($possiblePaths as $path) {
    $output = [];
    $returnVar = 0;
    @exec("{$path} --version 2>&1", $output, $returnVar);
    if ($returnVar === 0) {
        $inkscapePath = $path;
        break;
    }
}

if ($inkscapePath) {
    $command = sprintf(
        '"%s" --export-type=png --export-filename="%s" --export-width=400 "%s"',
        $inkscapePath,
        $pngPath,
        $svgPath
    );
    
    exec($command, $output, $returnVar);
    if ($returnVar === 0 && file_exists($pngPath)) {
        echo "✓ PNG logo created successfully using Inkscape at {$pngPath}\n";
        echo "  Size: " . filesize($pngPath) . " bytes\n";
        exit(0);
    } else {
        echo "Inkscape conversion failed.\n";
    }
}

// Fallback: Instructions for manual conversion
echo "\n";
echo "⚠ Could not automatically convert SVG to PNG.\n";
echo "\n";
echo "Please convert the logo manually:\n";
echo "1. Open {$svgPath} in an image editor (e.g., Inkscape, GIMP, Photoshop)\n";
echo "2. Export as PNG with:\n";
echo "   - Width: 400px (or maintain aspect ratio)\n";
echo "   - Background: Transparent\n";
echo "   - Format: PNG-24 or PNG-32\n";
echo "3. Save as: {$pngPath}\n";
echo "\n";
echo "Or install ImageMagick extension:\n";
echo "  - Ubuntu/Debian: sudo apt-get install php-imagick\n";
echo "  - Windows: Download from https://pecl.php.net/package/imagick\n";
echo "\n";
exit(1);
