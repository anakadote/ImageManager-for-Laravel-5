<?php namespace Anakadote\ImageManager;

class ImageManager {
	
	protected $file;
	protected $file_path;
	protected $url_path;
	protected $filename;
	protected $error_filename;
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
	 * Constructor
	 */
	public function __construct($error_filename='error.jpg')
	{
		$this->error_filename = $error_filename;
		$this->errors = array();
		
		if( ! function_exists('gd_info')){
			throw new \Exception('GD Library required in package Anakadote\ImageManager.');
		}
		
		ini_set("memory_limit", "512M");
	}
	
	
	/** 
	 * Resize image according to supplied parameters
	 *
	 * @param string $file // Fully qualified name of image file
	 * @param int $width
	 * @param int $height
	 * @param string $mode
	 * @param int $quality
	 */
	public function getImagePath($file, $width, $height, $mode, $quality=90)
	{	
		
		// Separate file into name and paths		
		$this->parseFileName($file);
		
		$this->width = $width;
		$this->height = $height;
		$this->mode = $mode;
		$this->quality = $quality;
		
		
		// Use error image if file cannot be found
		if( empty($file) || ! file_exists($file) || is_dir($file)){
			return $this->errorHandler();
		}
		
					
		// File already there so don't bother creating it
		if(file_exists($this->getPath(true))) return $this->getPath();
		
		
		// Make sure file type is supported
		$this->image_info = getimagesize($this->file);
		
		switch ($this->image_info['mime']) {
			
		case 'image/gif':
			if(imagetypes() & IMG_GIF) {
				$this->image = imagecreatefromgif($this->file);
			} 
			else {
				$_errors[] = 'GIF images are not supported';
				$this->errorHandler();
			}
			break;
			
		case 'image/jpeg':
			if(imagetypes() & IMG_JPG) {
				$this->adjustImageOrientation();
				$this->image = imagecreatefromjpeg($this->file);
			} 
			else {
				$_errors[] = 'JPEG images are not supported';
				$this->errorHandler();
			}
			break;
			
		case 'image/jpg':
			if(imagetypes() & IMG_JPG) {
				$this->adjustImageOrientation();
				$this->image = imagecreatefromjpeg($this->file);
			} 
			else {
				$_errors[] = 'JPG images are not supported';
				$this->errorHandler();
			}
			break;
			
		case 'image/png':
			if(imagetypes() & IMG_PNG) {
				$this->image = imagecreatefrompng($this->file);
			} 
			else {
				$_errors[] = 'PNG images are not supported';
				$this->errorHandler();
			}
			break;
			
		default:
			$_errors[] = $this->image_info['mime'] . ' images are not supported';
			$this->errorHandler();
			break;
		}
		
		$this->resize();
		$this->save();
					
		return $this->getPath();
	}
	
	
	/** 
	 * Get full image path including filename
	 *
	 * @param bool $from_root If true, return fully qualified path. If false, return public path to image
	 * @return string
	 */
	public function getPath($from_root=false)
	{
		return $this->getFolder($from_root) . $this->filename;
	}
	
	
	/**
	 * Get the directory of the image if it exists, otherwise, create it and return it
	 *
	 * @return string
	 */
	protected function getFolder($from_root=false)
	{
		$foldername = $this->width . "-" . $this->height; // First make dimensions folder
		
		if( ! file_exists($this->file_path . "/" . $foldername)){
						
			if( ! mkdir($this->file_path . "/" . $foldername, 0777)){
				throw new \Exception('Error creating directory');
			}
		}
		
		$foldername = $foldername . "/" . $this->mode; // Then make mode folder
		if( ! file_exists($this->file_path . "/" . $foldername)){ 
			
			if( ! mkdir($this->file_path . "/" . $foldername, 0777)){
				throw new \Exception('Error creating directory');
			}
		}
		
		
		if($from_root){
			return $this->file_path . "/" . $this->width . "-" . $this->height . "/" . $this->mode;
		
		} else {
			return $this->url_path . "/" . $this->width . "-" . $this->height . "/" . $this->mode;
		}
	}
	
	
	/** 
	 * Get an array of errors
	 *
	 * @return array
	 */
	public function getErrors()
	{
		return $this->errors;	
	}
	
	
	/**
	 * Separate file name into name and paths	
	 *
	 * @param string $file
	 */
	private function parseFileName($file)
	{
		$this->file 			= $file;
		$this->file_path 	= dirname($this->file);
		$this->url_path 	= str_replace(public_path(), "", $this->file_path);
		$this->filename 	= str_replace($this->file_path, "", $this->file);
	}
	
	
	/** 
	 * Resize an image using the provided mode and dimensions
	 */
	protected function resize()
	{
		$width = $this->width;
		$height = $this->height;
		$orig_width = imagesx($this->image);
		$orig_height = imagesy($this->image);
		
		// Determine new image dimensions
		if($this->mode === "crop"){ // Crop image
			
			$max_width = $crop_width = $width;
			$max_height = $crop_height = $height;
		
			$x_ratio = @($max_width / $orig_width);
			$y_ratio = @($max_height / $orig_height);
			
			if($orig_width > $orig_height){ // Original is wide
				$height = $max_height;
				$width = ceil($y_ratio * $orig_width);
				
			} elseif($orig_height > $orig_width){ // Original is tall
				$width = $max_width;
				$height = ceil($x_ratio * $orig_height);
				
			} else { // Original is square
				$this->mode = "fit";
				
				return $this->resize();
			}
			
			// Adjust if the crop width is less than the requested width to avoid black lines
			if($width < $crop_width){
				$width = $max_width;
				$height = ceil($x_ratio * $orig_height);
			}
			
		} elseif($this->mode === "fit"){ // Fits the image according to aspect ratio to within max height and width
			$max_width = $width;
			$max_height = $height;
		
			$x_ratio = @($max_width / $orig_width);
			$y_ratio = @($max_height / $orig_height);
			
			if( ($orig_width <= $max_width) && ($orig_height <= $max_height) ){  // Image is smaller than max height and width so don't resize
				$tn_width = $orig_width;
				$tn_height = $orig_height;
			
			} elseif(($x_ratio * $orig_height) < $max_height){ // Wider rather than taller
				$tn_height = ceil($x_ratio * $orig_height);
				$tn_width = $max_width;
			
			} else { // Taller rather than wider
				$tn_width = ceil($y_ratio * $orig_width);
				$tn_height = $max_height;
			}
			
			$width = $tn_width;
			$height = $tn_height;
			
		} elseif($this->mode === "fit-x"){ // Sets the width to the max width and the height according to aspect ratio (will stretch if too small)
			$height = @round($orig_height * $width / $orig_width);
			
			if($orig_height <= $height){ // Don't stretch if smaller
				$width = $orig_width;
				$height = $orig_height;
			}
			
		} elseif($this->mode === "fit-y"){ // Sets the height to the max height and the width according to aspect ratio (will stretch if too small)
			$width = @round($orig_width * $height / $orig_height);
			
			if($orig_width <= $width){ // Don't stretch if smaller
				$width = $orig_width;
				$height = $orig_height;
			}
		} else {
			throw new \Exception('Invalid mode: ' . $this->mode);
		}
		
		
		
		// Resize
		$this->temp = imagecreatetruecolor($width, $height);
		
		// Preserve transparency if a png
		if($this->image_info['mime'] == 'image/png'){
			imagealphablending($this->temp, false);
			imagesavealpha($this->temp, true);
		}
		
		imagecopyresampled($this->temp, $this->image, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
		$this->sync();
		
		
		
		// Cropping?
		if($this->mode === "crop"){	 
			$orig_width 	= imagesx($this->image);
			$orig_height 	= imagesy($this->image);
			
			$x_mid = $orig_width/2;  // horizontal middle
    	$y_mid = $orig_height/2; // vertical middle
			
			$this->temp = imagecreatetruecolor($crop_width, $crop_height);
			
			// Preserve transparency if a png
			if($this->image_info['mime'] == 'image/png'){
				imagealphablending($this->temp, false);
				imagesavealpha($this->temp, true);
			}

			imagecopyresampled($this->temp, $this->image, 0, 0, ($x_mid-($crop_width/2)), ($y_mid-($crop_height/2)), $crop_width, $crop_height, $crop_width, $crop_height);
			$this->sync();
		}
	}
	
	
	/**
	 * Correct the image's orientation (due to digital cameras)
	 */
	protected function adjustImageOrientation()
	{        
		$exif = @exif_read_data($this->file);
		
		if($exif && isset($exif['Orientation'])) {
			$orientation = $exif['Orientation'];
			
			if($orientation != 1){
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
				
				if($deg) 		$img = imagerotate($img, $deg, 0); 
				if($mirror) $img = $this->mirrorImage($img);
				
				$this->image = str_replace('.jpg', "-O$orientation.jpg", $this->file); 
				imagejpeg($img, $this->file, $this->quality);
			}
		}
	}
	
	
	/**
	 * Flip/mirror an image
	 *
	 * @param resource $image
	 * @return resource
	 */
	protected function mirrorImage($image)
	{
		$width 	= imagesx($image);
		$height = imagesy($image);
		
		$src_x = $width -1;
		$src_y = 0;
		$src_width 	= -$width;
		$src_height = $height;
		
		$imgdest = imagecreatetruecolor($width, $height);
		
		if( imagecopyresampled($imgdest, $image, 0, 0, $src_x, $src_y, $width, $height, $src_width, $src_height) ){
			return $imgdest;
		}
		return $image;
	}
	
	
	/** 
	 * Delete an image and all generated child images
	 *
	 * @param string $file
	 */
	public function deleteImage($file)
	{	
		
		// Separate file into name and paths		
		$this->parseFileName($file);
		
		$dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->file_path));
		foreach($dir as $dir_file){			
			$parts = explode('/', str_replace($this->file_path, '', $dir_file));
						
			if($this->filename == "/" . $parts[ count($parts)-1 ]){
				unlink($dir_file);
			}
		}
		
		@unlink($file);
	}
	
	
	/** 
	 * Set the $image as an alias of $temp, then unset $temp
	 */
	protected function sync()
	{
		$this->image =& $this->temp;
		unset($this->temp);
	}
	
	
	/** 
	 * Send image header
	 *
	 * @param string $mime Mime type of the image to be displayed
	 */
	protected function sendHeader($mime='jpeg')
	{
		header('Content-Type: image/' . $mime);
	}
	
	
	/** 
	 * Display image to screen
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
			$_errors[] = $this->image_info['mime'].' images are not supported';
			$this->errorHandler();
			break;
		}
	}
	
	
	/** 
	 * Save image to server
	 */
	protected function save()
	{
		switch ($this->image_info['mime']) {
		
		case 'image/gif':
			imagegif($this->image, $this->getPath(true));
			break;
		
		case 'image/jpeg':
			imagejpeg($this->image, $this->getPath(true), $this->quality);
			break;
		
		case 'image/jpg':
			imagejpeg($this->image, $this->getPath(true), $this->quality);
			break;
		
		case 'image/png':
			imagepng($this->image, $this->getPath(true), round($this->quality / 10));
			break;
		
		default:
			$_errors[] = $this->image_info['mime'].' images are not supported';
			$this->errorHandler();
			break;
		}
		
		chmod($this->getPath(true), 0777);
	}
	
	
	/** 
	 * Display error image
	 */
	protected function errorHandler()
	{
		$this->file = public_path() . '/vendor/anakadote/image-manager/' . $this->error_filename;
						
		if(file_exists($this->file)){
			return $this->getImagePath($this->file, $this->width, $this->height, $this->mode, $this->quality);
		
		} else {
			$this->errors[] = 'Error image not found.';	
		}
	}
	
	
	/** 
	 * Destructor: Destroy image references from memory
	 */
	public function __destruct()
	{
		if( isset($this->image) ) imageDestroy($this->image);
		if( isset($this->temp) ) imageDestroy($this->temp);
	}
}

?>