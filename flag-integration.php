<?php 
class ewwwflag {
	/* initializes the flagallery integration functions */
	function ewwwflag() {
		add_filter('flag_manage_images_columns', array(&$this, 'ewww_manage_images_columns'));
		add_action('flag_manage_gallery_custom_column', array(&$this, 'ewww_manage_image_custom_column'), 10, 2);
		add_action('flag_manage_images_bulkaction', array(&$this, 'ewww_manage_images_bulkaction'));
		add_action('flag_manage_galleries_bulkaction', array(&$this, 'ewww_manage_galleries_bulkaction'));
		add_action('flag_manage_post_processor_images', array(&$this, 'ewww_flag_bulk'));
		add_action('flag_manage_post_processor_galleries', array(&$this, 'ewww_flag_bulk'));
		add_action('flag_thumbnail_created', array(&$this, 'ewww_added_new_image'));
		add_action('flag_image_resized', array(&$this, 'ewww_added_new_image'));
		add_action('admin_action_ewww_flag_manual', array(&$this, 'ewww_flag_manual'));
		add_action('admin_menu', array(&$this, 'ewww_flag_bulk_menu'));
		add_action('admin_enqueue_scripts', array(&$this, 'ewww_flag_bulk_script'));
		add_action('wp_ajax_bulk_flag_init', array(&$this, 'ewww_flag_bulk_init'));
		add_action('wp_ajax_bulk_flag_filename', array(&$this, 'ewww_flag_bulk_filename'));
		add_action('wp_ajax_bulk_flag_loop', array(&$this, 'ewww_flag_bulk_loop'));
		add_action('wp_ajax_bulk_flag_cleanup', array(&$this, 'ewww_flag_bulk_cleanup'));
		register_setting('ewww_image_optimizer_options', 'ewww_image_optimizer_bulk_flag_resume');
		register_setting('ewww_image_optimizer_options', 'ewww_image_optimizer_bulk_flag_attachments');
	}

	/* adds the Bulk Optimize page to the menu */
	function ewww_flag_bulk_menu () {
		add_submenu_page('flag-overview', 'FlAG Bulk Optimize', 'Bulk Optimize', 'FlAG Manage gallery', 'flag-bulk-optimize', array (&$this, 'ewww_flag_bulk'));
	}

	/* add bulk optimize action to image management page */
	function ewww_manage_images_bulkaction () {
		echo '<option value="bulk_optimize_images">Bulk Optimize</option>';
	}

	/* add bulk optimize action to gallery management page */
	function ewww_manage_galleries_bulkaction () {
		echo '<option value="bulk_optimize_galleries">Bulk Optimize</option>';
	}

	// Handles the bulk html output
	function ewww_flag_bulk () {
		// if there is POST data, make sure bulkaction and doaction are the values we want
		if (!empty($_POST)) {
			// if there is no requested bulk action, do nothing
			if (empty($_REQUEST['bulkaction'])) {
				return;
			}
			// if there is no media to optimize, do nothing
			if (empty($_REQUEST['doaction']) || !is_array($_REQUEST['doaction'])) {
				return;
			}
		}
		// get the previously stored attachments array from the options table
		$attachments = get_option('ewww_image_optimizer_bulk_flag_attachments');
		// bail-out if there aren't any images to optimize
		if (count($attachments) < 1) {
			echo '<p>You don’t appear to have uploaded any images yet.</p>';
			return;
		}
		?>
		<div class="wrap"><div id="icon-upload" class="icon32"></div><h2>GRAND FlAGallery Bulk Optimize</h2>
		<?php
		// Retrieve the value of the 'bulk resume' option and set the button text for the form to use
		$resume = get_option('ewww_image_optimizer_bulk_flag_resume');
		if (empty($resume)) {
			$button_text = 'Start optimizing';
		} else {
			$button_text = 'Resume previous bulk operation';
		}
		?>
		<div id="bulk-loading"></div>
		<div id="bulk-progressbar"></div>
		<div id="bulk-counter"></div>
		<div id="bulk-status"></div>
		<div id="bulk-forms"><p>This tool can optimize large batches (or all) of images from your media library.</p>
		<p>We have <?php echo count($attachments); ?> images to optimize.</p>
		<form id="bulk-start" method="post" action="">
			<input type="submit" class="button-secondary action" value="<?php echo $button_text; ?>" />
		</form>
		<?php
		// if there was a previous operation, offer the option to reset the option in the db
		if (!empty($resume)):
		?>
			<p>If you would like to start over again, press the <b>Reset Status</b> button to reset the bulk operation status.</p>
			<form method="post" action="">
				<?php wp_nonce_field( 'ewww-image-optimizer-bulk', '_wpnonce'); ?>
				<input type="hidden" name="reset" value="1">
				<button id="bulk-reset" type="submit" class="button-secondary action">Reset Status</button>
			</form>
		<?php
		endif;
		echo '</div></div>';
	}

	// prepares the bulk operation and includes the necessary javascript files
	function ewww_flag_bulk_script($hook) {
		// make sure we are being hooked from a valid location
		if ($hook != 'flagallery_page_flag-bulk-optimize' && $hook != 'flagallery_page_flag-manage-gallery')
			return;
		// if there is no requested bulk action, do nothing
		if ($hook == 'flagallery_page_flag-manage-gallery' && empty($_REQUEST['bulkaction'])) {
			return;
		}
		// if there is no media to optimize, do nothing
		if ($hook == 'flagallery_page_flag-manage-gallery' && (empty($_REQUEST['doaction']) || !is_array($_REQUEST['doaction']))) {
			return;
		}
		$ids = null;
		// reset the resume flag if the user requested it
		if (!empty($_REQUEST['reset'])) {
			update_option('ewww_image_optimizer_bulk_flag_resume', '');
		}
		// get the resume flag from the db
		$resume = get_option('ewww_image_optimizer_bulk_flag_resume');
		// check if we are being asked to optimize galleries or images rather than a full bulk optimize
		if (!empty($_REQUEST['doaction'])) {
			// see if the bulk operation requested is from the manage images page
			if ($_REQUEST['page'] == 'manage-images' && $_REQUEST['bulkaction'] == 'bulk_optimize_images') {
				// check the referring page and nonce
				check_admin_referer('flag_updategallery');
				// we don't allow previous operations to resume if the user is asking to optimize specific images
				update_option('ewww_image_optimizer_bulk_flag_resume', '');
				// retrieve the image IDs from POST
				$ids = array_map( 'intval', $_REQUEST['doaction']);
			}
			// see if the bulk operation requested is from the manage galleries page
			if ($_REQUEST['page'] == 'manage-galleries' && $_REQUEST['bulkaction'] == 'bulk_optimize_galleries') {
				// check the referring page and nonce
				check_admin_referer('flag_bulkgallery');
				global $flagdb;
				// we don't allow previous operations to resume if the user is asking to optimize specific galleries
				update_option('ewww_image_optimizer_bulk_flag_resume', '');
				$ids = array();
				// for each gallery ID, retrieve the image IDs within
				foreach ($_REQUEST['doaction'] as $gid) {
					$gallery_list = $flagdb->get_gallery($gid);
					// for each image ID found, put it onto the $ids array
					foreach ($gallery_list as $image) {
						$ids[] = $image->pid;
					}	
				}
			}
		// if there is an operation to resume, get those IDs from the db
		} elseif (!empty($resume)) {
			$ids = get_option('ewww_image_optimizer_bulk_flag_attachments');
		// otherwise, if we are on the main bulk optimize page, just get all the IDs available
		} elseif ($hook == 'flagallery_page_flag-bulk-optimize') {
			global $wpdb;
			$ids = $wpdb->get_col("SELECT pid FROM $wpdb->flagpictures ORDER BY sortorder ASC");
		}
		// store the IDs to optimize in the options table of the db
		update_option('ewww_image_optimizer_bulk_flag_attachments', $ids);
		global $wp_version;
		$my_version = $wp_version;
		$my_version = substr($my_version, 0, 3);
		if ($my_version < 3) {
			// replace the built-in jquery script
			wp_deregister_script('jquery');
			wp_register_script('jquery', plugins_url('/jquery-1.9.1.min.js', __FILE__), false, '1.9.1');
		}
		// add a custom jquery-ui script that contains the progressbar widget
		wp_enqueue_script('ewwwjuiscript', plugins_url('/jquery-ui-1.10.2.custom.min.js', __FILE__), false);
		// add the EWWW IO javascript
		wp_enqueue_script('ewwwbulkscript', plugins_url('/eio.js', __FILE__), array('jquery'));
		// add the styling for the progressbar
		wp_enqueue_style('jquery-ui-progressbar', plugins_url('jquery-ui-1.10.1.custom.css', __FILE__));
		// encode the IDs for javascript use
		$ids = json_encode($ids);
		// prepare a few variables to be used by the javascript code
		wp_localize_script('ewwwbulkscript', 'ewww_vars', array(
				'_wpnonce' => wp_create_nonce('ewww-image-optimizer-bulk'),
				'gallery' => 'flag',
				'attachments' => $ids
			)
		);
	}
	/* flag_added_new_image hook - optimize newly uploaded images */
	function ewww_added_new_image ($image) {
		// make sure the image path is set
		if (isset($image->imagePath)) {
			// optimize the full size
			$res = ewww_image_optimizer($image->imagePath, 3, false, false);
			// optimize the thumbnail
			$tres = ewww_image_optimizer($image->thumbPath, 3, false, true);
			// get the image ID
			$pid = $image->pid;
			// update the image metadata in the db
			flagdb::update_image_meta($pid, array('ewww_image_optimizer' => $res[1]));
		}
	}

	/* Manually process an image from the gallery */
	function ewww_flag_manual() {
		// make sure the current user has appropriate permissions
		if ( FALSE === current_user_can('upload_files') ) {
			wp_die(__('You don\'t have permission to work with uploaded files.', EWWW_IMAGE_OPTIMIZER_DOMAIN));
		}
		// make sure we have an attachment ID
		if ( FALSE === isset($_GET['attachment_ID'])) {
			wp_die(__('No attachment ID was provided.', EWWW_IMAGE_OPTIMIZER_DOMAIN));
		}
		$id = intval($_GET['attachment_ID']);
		// retrieve the metadata for the image ID
		$meta = new flagMeta( $id );
		$file_path = $meta->image->imagePath;
		// optimize the full size
		$res = ewww_image_optimizer($file_path, 3, false, false);
		// update the metadata for the full-size image
		flagdb::update_image_meta($id, array('ewww_image_optimizer' => $res[1]));
		$thumb_path = $meta->image->thumbPath;
		// optimize the thumbnail
		ewww_image_optimizer($thumb_path, 3, false, true);
		// get the referring page
		$sendback = wp_get_referer();
		// and clean it up a bit
		$sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
		// send the user back where they came from
		wp_redirect($sendback);
		exit(0);
	}

	/* initialize bulk operation */
	function ewww_flag_bulk_init() {
		if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Cheatin&#8217; eh?' ) );
		}
		// set the resume flag to indicate the bulk operation is in progress
		update_option('ewww_image_optimizer_bulk_flag_resume', 'true');
		$loading_image = plugins_url('/wpspin.gif', __FILE__);
		// output the initial message letting the user know we are starting
		echo "<p>Optimizing&nbsp;<img src='$loading_image' alt='loading'/></p>";
		die();
	}

	/* output the filename of the currently optimizing image */
	function ewww_flag_bulk_filename() {
		if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Cheatin&#8217; eh?' ) );
		}
		// need this file to work with flag meta
		require_once(WP_CONTENT_DIR . '/plugins/flash-album-gallery/lib/meta.php');
		$id = $_POST['attachment'];
		// retrieve the meta for the current ID
		$meta = new flagMeta($id);
		$loading_image = plugins_url('/wpspin.gif', __FILE__);
		// retrieve the filename for the current image ID
		$file_name = esc_html($meta->image->filename);
		// and let the user know which image we are working on currently
		echo "<p>Optimizing... <b>" . $file_name . "</b>&nbsp;<img src='$loading_image' alt='loading'/></p>";
		die();
	}
		
	/* process each image and it's thumbnail during the bulk operation */
	function ewww_flag_bulk_loop() {
		if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Cheatin&#8217; eh?' ) );
		}
		// need this file to work with flag meta
		require_once(WP_CONTENT_DIR . '/plugins/flash-album-gallery/lib/meta.php');
		// record the starting time for the current image (in microseconds)
		$started = microtime(true);
		$id = $_POST['attachment'];
		// get the image meta for the current ID
		$meta = new flagMeta($id);
		$file_path = $meta->image->imagePath;
		// optimize the full-size version
		$fres = ewww_image_optimizer($file_path, 3, false, false);
		// and store the results in the metadata
		flagdb::update_image_meta($id, array('ewww_image_optimizer' => $fres[1]));
		// let the user know what happened
		printf( "<p>Optimized image: <strong>%s</strong><br>", esc_html($meta->image->filename) );
		printf( "Full size – %s<br>", $fres[1] );
		$thumb_path = $meta->image->thumbPath;
		// optimize the thumbnail
		$tres = ewww_image_optimizer($thumb_path, 3, false, true);
		// and let the user know the results
		printf( "Thumbnail – %s<br>", $tres[1] );
		// determine how much time the image took to process
		$elapsed = microtime(true) - $started;
		// and output it to the user
		echo "Elapsed: " . round($elapsed, 3) . " seconds</p>";
		// retrieve the list of attachments left to work on
		$attachments = get_option('ewww_image_optimizer_bulk_flag_attachments');
		// take the first image off the list
		array_shift($attachments);
		// and send the list back to the db
		update_option('ewww_image_optimizer_bulk_flag_attachments', $attachments);
		die();
	}

	/* finish the bulk operation, and clear out the bulk_flag options */
	function ewww_flag_bulk_cleanup() {
		if (!wp_verify_nonce( $_REQUEST['_wpnonce'], 'ewww-image-optimizer-bulk' ) || !current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Cheatin&#8217; eh?' ) );
		}
		// reset the bulk flags in the db
		update_option('ewww_image_optimizer_bulk_flag_resume', '');
		update_option('ewww_image_optimizer_bulk_flag_attachments', '');
		// and let the user know we are done
		echo '<p><b>Finished Optimization!</b></p>';
		die();
	}

	/* flag_manage_images_columns hook - add a column on the gallery display */
	function ewww_manage_images_columns( $columns ) {
		$columns['ewww_image_optimizer'] = 'Image Optimizer';
		return $columns;
	}

	/* flag_manage_image_custom_column hook - output the EWWW IO information on the gallery display */
	function ewww_manage_image_custom_column( $column_name, $id ) {
		// check to make sure we're outputing our custom column
		if( $column_name == 'ewww_image_optimizer' ) {
			// get the metadata
			$meta = new flagMeta( $id );
			// grab the image status from the meta
			$status = $meta->get_META('ewww_image_optimizer');
			$msg = '';
			// get the image path from the meta
			$file_path = $meta->image->imagePath;
			// get the mimetype
			$type = ewww_image_optimizer_mimetype($file_path, 'i');
			// get the file size
			$file_size = size_format(filesize($file_path), 2);
			$file_size = str_replace('B ', 'B', $file_size);
			//$file_size = ewww_image_optimizer_format_bytes(filesize($file_path));
			$valid = true;
			// if we don't have a valid tool for the image type, output the appropriate message
	                switch($type) {
        	                case 'image/jpeg':
                	                if(!EWWW_IMAGE_OPTIMIZER_JPEGTRAN && !EWWW_IMAGE_OPTIMIZER_CLOUD) {
                        	                $valid = false;
	     	                                $msg = '<br>' . __('<em>jpegtran</em> is missing');
	                                }
					break;
				case 'image/png':
					if(!EWWW_IMAGE_OPTIMIZER_PNGOUT && !EWWW_IMAGE_OPTIMIZER_OPTIPNG && !EWWW_IMAGE_OPTIMIZER_CLOUD) {
						$valid = false;
						$msg = '<br>' . __('<em>optipng/pngout</em> is missing');
					}
					break;
				case 'image/gif':
					if(!EWWW_IMAGE_OPTIMIZER_GIFSICLE && !EWWW_IMAGE_OPTIMIZER_CLOUD) {
						$valid = false;
						$msg = '<br>' . __('<em>gifsicle</em> is missing');
					}
					break;
				default:
					$valid = false;
			}
			// let user know if the file type is unsupported
			if($valid == false) {
				print __('Unsupported file type', EWWW_IMAGE_OPTIMIZER_DOMAIN) . $msg;
				return;
			}
			// output the image status if we know it
			if (!empty($status)) {
				echo $status;
				print "<br>Image Size: $file_size";
				printf("<br><a href=\"admin.php?action=ewww_flag_manual&amp;attachment_ID=%d\">%s</a>",
				$id,
				__('Re-optimize', EWWW_IMAGE_OPTIMIZER_DOMAIN));
			// otherwise, tell the user that they can optimize the image now
			} else {
				print __('Not processed', EWWW_IMAGE_OPTIMIZER_DOMAIN);
				print "<br>Image Size: $file_size";
				printf("<br><a href=\"admin.php?action=ewww_flag_manual&amp;attachment_ID=%d\">%s</a>",
				$id,
				__('Optimize now!', EWWW_IMAGE_OPTIMIZER_DOMAIN));
			}
		}
	}
}

add_action( 'init', 'ewwwflag' );

function ewwwflag() {
	global $ewwwflag;
	$ewwwflag = new ewwwflag();
}

