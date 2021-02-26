<?php

namespace Anakadote\ImageManager;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ImageManager
{
    protected $file;
    protected $file_path;
    protected $url_path;
    protected $filename;
    protected $error_file;
    protected $image;
    protected $temp;
    protected $mode; // crop, fit, fit-x, fit-y
    protected $url;
    protected $width;
    protected $height;
    protected $quality;
    protected $image_info;
    protected $errors;
    
    /** 
     * Constructor.
     *
     * @param  string  $error_filename
     * @throws \Exception
     */
    public function __construct($error_filename = 'error.jpg')
    {        
        $this->error_file = public_path() . '/vendor/anakadote/image-manager/' . $error_filename;
        $this->errors = array();
        
        if (! function_exists('gd_info')) {
            throw new Exception('GD Library is required in package Anakadote\ImageManager.');
        }
        
        ini_set('memory_limit', '512M');
    }
    
    /** 
     * Resize image according to supplied parameters, and return its path.
     *
     * @param  string      $file  Path to the file.
     * @param  int         $width
     * @param  int         $height
     * @param  string      $mode
     * @param  int         $quality
     * @param  string|nul  $format  Convert the image to the given format/extension i.e. "webp".
     * @return string
     */
    public function getImagePath($file, $width, $height, $mode, $quality = 90, $format = null)
    {
        // Separate file into name and paths.
        $this->parseFileName($file);
        
        $this->width = $width;
        $this->height = $height;
        $this->mode = $mode;
        $this->quality = $quality;
        
        // Use error image if file cannot be found.
        if (empty($file) || ! file_exists($file) || is_dir($file)) {
            return $this->errorHandler();
        }
        
        // File already there so don't bother creating it.
        if (file_exists($this->getPath(true, $format))) {
            return $this->getPath(false, $format);
        }

        // SVG? Simply return the URL path to the image.
        if ($this->getExtension($this->filename) === 'svg') {
            return $this->url_path . $this->filename;
        }
        
        // Make sure file type is supported.
        $this->image_info = getimagesize($this->file);
        if (! $this->image_info || ! isset($this->image_info['mime'])) {
            $_errors[] = 'Invalid file type';
            return $this->errorHandler();
        }
        
        switch ($this->image_info['mime']) {
            
        case 'image/gif':
            if (imagetypes() & IMG_GIF) {
                $this->image = imagecreatefromgif ($this->file);
            } 
            else {
                $_errors[] = 'GIF images are not supported';
                return $this->errorHandler();
            }
            break;
            
        case 'image/jpeg':
        case 'image/jpg':
            if (imagetypes() & IMG_JPG) {
                $this->adjustImageOrientation();
                $this->image = imagecreatefromjpeg($this->file);
            } 
            else {
                $_errors[] = 'JPG images are not supported';
                return $this->errorHandler();
            }
            break;
            
        case 'image/png':
            if (imagetypes() & IMG_PNG) {
                $this->image = imagecreatefrompng($this->file);
            } 
            else {
                $_errors[] = 'PNG images are not supported';
                return $this->errorHandler();
            }
            break;
            
        case 'image/webp':
            if (imagetypes() & IMG_WEBP) {
                $this->image = imagecreatefromwebp($this->file);
            } 
            else {
                $_errors[] = 'WEBP images are not supported';
                return $this->errorHandler();
            }
            break;
            
        default:
            $_errors[] = $this->image_info['mime'] . ' images are not supported';
            return $this->errorHandler();
        }

        $this->resize();

        if ($format) {
            $this->convertAndSave($format);
        } else {
            $this->save();
        }
                    
        return $this->getPath(false, $format);
    }
    
    /** 
     * Get full image path including filename.
     *
     * @param  bool  $from_root  If true, return fully qualified path. If false, return public path to image.
     * @return string
     */
    public function getPath($from_root = false, $format = null)
    {
        $filename = $this->filename;
        if ($format) {
            $parts = explode('.', $this->filename);
            $filename = $parts[0] . '.' . $format;
        }

        return $this->getFolder($from_root) . $filename;
    }
    
    /**
     * Get the directory of the image if it exists, otherwise, create it and return it.
     *
     * @param  bool  $from_root  If true, returns fully qualified path. If false, returns public path to image.
     * @throws \Exception
     * @return string
     */
    protected function getFolder($from_root = false)
    {
        $foldername = $this->width . "-" . $this->height; // First make dimensions folder
        
        if (! file_exists($this->file_path . "/" . $foldername)) {
            
            if (! mkdir($this->file_path . "/" . $foldername, 0777)) {
                throw new Exception('Error creating directory');
            }
        }
        
        $foldername = $foldername . "/" . $this->mode; // Then make mode folder
        if (! file_exists($this->file_path . "/" . $foldername)) { 
            
            if (! mkdir($this->file_path . "/" . $foldername, 0777)) {
                throw new Exception('Error creating directory');
            }
        }
        
        if ($from_root) {
            return $this->file_path . "/" . $this->width . "-" . $this->height . "/" . $this->mode;
        }
        
        return $this->url_path . "/" . $this->width . "-" . $this->height . "/" . $this->mode;
    }
    
    /** 
     * Get an array of errors.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }
    
    /**
     * Separate file name into name and path.
     *
     * @param  string  $file
     */
    private function parseFileName($file)
    {
        $this->file      = str_replace('\\', '/', $file);
        $this->file_path = dirname($this->file);
        $this->url_path  = str_replace(str_replace('\\', '/', public_path()), "", $this->file_path);
        $this->filename  = str_replace($this->file_path, "", $this->file);
    }
    
    /** 
     * Resize an image using the provided mode and dimensions.
     *
     * @throws \Exception
     */
    protected function resize()
    {
        $width = $this->width;
        $height = $this->height;
        $orig_width = imagesx($this->image);
        $orig_height = imagesy($this->image);
        
        // Determine new image dimensions.
        if ($this->mode === "crop") { // Crop image
            
            $max_width = $crop_width = $width;
            $max_height = $crop_height = $height;
        
            $x_ratio = @($max_width / $orig_width);
            $y_ratio = @($max_height / $orig_height);
            
            if ($orig_width > $orig_height) { // Original is wide.
                $height = $max_height;
                $width = ceil($y_ratio * $orig_width);
                
            } elseif ($orig_height > $orig_width) { // Original is tall.
                $width = $max_width;
                $height = ceil($x_ratio * $orig_height);
                
            } else { // Original is square.
                $this->mode = "fit";
                
                return $this->resize();
            }
            
            // Adjust if the crop width is less than the requested width to avoid black lines.
            if ($width < $crop_width) {
                $width = $max_width;
                $height = ceil($x_ratio * $orig_height);
            }
            
        } elseif ($this->mode === "fit") { // Fits the image according to aspect ratio to within max height and width.
            $max_width = $width;
            $max_height = $height;
        
            $x_ratio = @($max_width / $orig_width);
            $y_ratio = @($max_height / $orig_height);
            
            if ( ($orig_width <= $max_width) && ($orig_height <= $max_height) ) { // Image is smaller than max height and width so don't resize.
                $tn_width = $orig_width;
                $tn_height = $orig_height;
            
            } elseif (($x_ratio * $orig_height) < $max_height) { // Wider rather than taller.
                $tn_height = ceil($x_ratio * $orig_height);
                $tn_width = $max_width;
            
            } else { // Taller rather than wider
                $tn_width = ceil($y_ratio * $orig_width);
                $tn_height = $max_height;
            }
            
            $width = $tn_width;
            $height = $tn_height;
            
        } elseif ($this->mode === "fit-x") { // Sets the width to the max width and the height according to aspect ratio (will stretch if too small).
            $height = @round($orig_height * $width / $orig_width);
            
            if ($orig_height <= $height) { // Don't stretch if smaller.
                $width = $orig_width;
                $height = $orig_height;
            }
            
        } elseif ($this->mode === "fit-y") { // Sets the height to the max height and the width according to aspect ratio (will stretch if too small).
            $width = @round($orig_width * $height / $orig_height);
            
            if ($orig_width <= $width) { // Don't stretch if smaller.
                $width = $orig_width;
                $height = $orig_height;
            }
        } else {
            throw new Exception('Invalid mode: ' . $this->mode);
        }
        

        // Resize.
        $this->temp = imagecreatetruecolor($width, $height);
        
        // Preserve transparency if a png.
        if ($this->image_info['mime'] == 'image/png') {
            imagealphablending($this->temp, false);
            imagesavealpha($this->temp, true);
        }
        
        imagecopyresampled($this->temp, $this->image, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
        $this->sync();
        
        
        // Cropping?
        if ($this->mode === "crop") {
            $orig_width  = imagesx($this->image);
            $orig_height = imagesy($this->image);
            
            $x_mid = $orig_width / 2;  // horizontal middle
            $y_mid = $orig_height / 2; // vertical middle
            
            $this->temp = imagecreatetruecolor($crop_width, $crop_height);
            
            // Preserve transparency if a png.
            if ($this->image_info['mime'] == 'image/png') {
                imagealphablending($this->temp, false);
                imagesavealpha($this->temp, true);
            }

            imagecopyresampled($this->temp, $this->image, 0, 0, ($x_mid - ($crop_width / 2)), ($y_mid - ($crop_height / 2)), $crop_width, $crop_height, $crop_width, $crop_height);
            $this->sync();
        }
    }
    
    /**
     * Correct the image's orientation (due to digital cameras).
     */
    protected function adjustImageOrientation()
    {        
        $exif = @exif_read_data($this->file);
        
        if ($exif && isset($exif['Orientation'])) {
            $orientation = $exif['Orientation'];
            
            if ($orientation != 1) {
                $img = imagecreatefromjpeg($this->file);
                
                $mirror = false;
                $deg    = 0;
                
                switch ($orientation) {
                    case 2:
                        $mirror = true;
                        break;
                    case 3:
                        $deg = 180;
                        break;
                    case 4:
                        $deg = 180;
                        $mirror = true;
                        break;
                    case 5:
                        $deg = 270;
                        $mirror = true;
                        break;
                    case 6:
                        $deg = 270;
                        break;
                    case 7:
                        $deg = 90;
                        $mirror = true;
                        break;
                    case 8:
                        $deg = 90;
                        break;
                }
                
                if ($deg)    $img = imagerotate($img, $deg, 0);
                if ($mirror) $img = $this->mirrorImage($img);
                
                $this->image = str_replace('.jpg', "-O$orientation.jpg", $this->file);
                imagejpeg($img, $this->file, $this->quality);
            }
        }
    }
    
    /**
     * Flip/mirror an image.
     *
     * @param  resource  $image
     * @return resource
     */
    protected function mirrorImage($image)
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        
        $src_x = $width -1;
        $src_y = 0;
        $src_width  = -$width;
        $src_height = $height;
        
        $imgdest = imagecreatetruecolor($width, $height);
        
        if (imagecopyresampled($imgdest, $image, 0, 0, $src_x, $src_y, $width, $height, $src_width, $src_height)) {
            return $imgdest;
        }
        
        return $image;
    }
    
    /** 
     * Get a file name's extension.
     *
     * @param  string  $file
     * @param  string
     */
    public function getExtension($filename)
    {    
        $parts = explode('.', $filename);
        return strtolower(array_pop($parts));
    }
    
    /** 
     * Generate a unique file name within a given destination.
     *
     * @param  string  $file
     * @param  string  $destination
     * @param  string
     */
    public function getUniqueFilename($filename, $destination)
    {    
        $filename = $this->slug($filename);
        
        if (! file_exists($destination . $filename)) {
            return $filename;
        }
        
        $parts = explode('.', $filename);
        $filename = $parts[0] .= '-' . uniqid() . '.' . $this->getExtension($filename);
        
        return $this->getUniqueFilename($filename, $destination);
    }
    
    /** 
     * Delete an image and all generated child images.
     *
     * @param  string  $file
     */
    public function deleteImage($file)
    {    
        // Separate file into name and paths
        $this->parseFileName($file);
        
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->file_path));
        foreach ($dir as $dir_file) {
            if ($this->filename == '/' . basename($dir_file)) {
                unlink($dir_file);
            }
        }
    }
    
    /** 
     * Set the $image as an alias of $temp, then unset $temp.
     */
    protected function sync()
    {
        $this->image =& $this->temp;
        unset($this->temp);
    }
    
    /** 
     * Send image header.
     *
     * @param  string  $mime  Mime type of the image to be displayed
     */
    protected function sendHeader($mime = 'jpeg')
    {
        header('Content-Type: image/' . $mime);
    }
    
    /** 
     * Display image to screen.
     */
    protected function show()
    {
        switch ($this->image_info['mime']) {
            case 'image/gif':
                $this->sendHeader('gif');
                imagegif($this->image, '');
                break;
            
            case 'image/jpeg':
                $this->sendHeader('jpg');
                imagejpeg($this->image, '', $this->quality);
                break;
            
            case 'image/jpg':
                $this->sendHeader('jpg');
                imagejpeg($this->image, '', $this->quality);
                break;
            
            case 'image/png':
                $this->sendHeader('png');
                imagepng($this->image, '', round($this->quality / 10));
                break;
            
            default:
                $_errors[] = $this->image_info['mime'] . ' images are not supported';
                return $this->errorHandler();
        }
    }
    
    /** 
     * Save image to server.
     */
    protected function save()
    {
        switch ($this->image_info['mime']) {
            case 'image/gif':
                imagegif($this->image, $this->getPath(true));
                break;
            
            case 'image/jpeg':
            case 'image/jpg':
                imagejpeg($this->image, $this->getPath(true), $this->quality);
                break;
            
            case 'image/png':
                imagepng($this->image, $this->getPath(true), round($this->quality / 10));
                break;
            
            case 'image/webp':
                imagewebp($this->image, $this->getPath(true), $this->quality);
                break;
            
            default:
                $_errors[] = $this->image_info['mime'] . ' images are not supported';
                return $this->errorHandler();
        }
        
        chmod($this->getPath(true), 0777);
    }
    
    /** 
     * Convert image and to server.
     *
     * @param  string  $format
     */
    protected function convertAndSave($format)
    {
        switch ($format) {
            case 'gif':
                imagegif($this->image, $this->getPath(true, $format));
                break;
            
            case 'jpeg':
            case 'jpg':
                imagejpeg($this->image, $this->getPath(true, $format), $this->quality);
                break;
            
            case 'png':
                imagepng($this->image, $this->getPath(true, $format), round($this->quality / 10));
                break;
            
            case 'webp':
                imagewebp($this->image, $this->getPath(true, $format), $this->quality);
                break;
            
            default:
                $_errors[] = $format . ' images are not supported';
                return $this->errorHandler();
        }
        
        chmod($this->getPath(true, $format), 0777);
    }
    
    /** 
     * Display error image.
     */
    protected function errorHandler()
    {
        $this->file = $this->error_file;
        
        if (file_exists($this->file)) {
            return $this->getImagePath($this->file, $this->width, $this->height, $this->mode, $this->quality);
        }
        
        $this->errors[] = 'Error image not found.';
    }
    
    /**
     * Generate a filename "slug".
     *
     * @param  string  $filename
     * @return string
     */
    private function slug($filename)
    {
        // Replace '_' with the word '-'
        $filename = preg_replace('![\_]+!u', '-', $filename);

        // Replace @ with the word 'at'
        $filename = str_replace('@', '-at-', $filename);

        // Remove all characters that are not the separator, letters, numbers, a period, or whitespace.
        $filename = preg_replace('![^\-\.\pL\pN\s]+!u', '', mb_strtolower($filename));

        // Replace all separator characters and whitespace by a single separator
        $filename = preg_replace('![\-\s]+!u', '-', $filename);

        return trim($filename, '-');
    }
    
    /** 
     * Destructor: Destroy image references from memory.
     */
    public function __destruct()
    {
        if (isset($this->image)) imageDestroy($this->image);
        if (isset($this->temp)) imageDestroy($this->temp);
    }
}
