<?php
/**
 * Plugin Name: WP S3 Uploader
 * Description: Offload files directly to Amazon S3
 * Author: Sahil Ahlawat
 * Version: 0.01
 * Requires at least: Wordpress 5.x
 * Requires PHP: 7.x
 * Network: true
 */


 require 'aws/aws-autoloader.php';

 use Aws\S3\S3Client;
 use Aws\Exception\AwsException;
 
 function offload_file_to_s3($file_path, $s3_bucket, $s3_key, $s3_secret, $s3_region) {
     error_log("Offloading file: " . $file_path);
 
     // Instantiate the client.
     $s3 = new S3Client([
         'version' => 'latest',
         'region'  => $s3_region,
         'credentials' => [
             'key'    => $s3_key,
             'secret' => $s3_secret,
         ],
     ]);
 
     $file_name = basename($file_path);
 
     try {
         // Upload data.
         $result = $s3->putObject([
             'Bucket' => $s3_bucket,
             'Key'    => $file_name,
             'SourceFile' => $file_path,
             'ACL'    => 'public-read'
         ]);
 
         error_log("File offloaded successfully: " . $file_name);
 
         // If the file was successfully offloaded, return the new URL
         return $result['ObjectURL'];
     } catch (AwsException $e) {
         // output error message if fails
         error_log("Error offloading file: " . $e->getMessage());
         return $e->getMessage();
     }
 }
 
 function offload_files() {
    error_log("Offloading files started");

    // Get the S3 credentials from the WordPress options
    $s3_bucket = get_option('sa_s3_bucket_name');
    $s3_key = get_option('sa_s3_bucket_key');
    $s3_secret = get_option('sa_s3_bucket_secret');
    $s3_region = get_option('sa_s3_bucket_region');

    global $wpdb;

$query = "
    SELECT p.* 
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'offloaded_to_s3'
    WHERE p.post_type = 'attachment'
    AND p.post_status = 'inherit'
    AND pm.meta_id IS NULL
    LIMIT 1000
";

$posts = $wpdb->get_results($query);

    error_log("Query is .".$query);
    error_log("Query is .".print_r($posts, true));

    foreach ($posts as $post) {
        setup_postdata($post);
        $id = $post->ID;
        $relative_file_path = get_attached_file($id);
        $uploads = wp_get_upload_dir();
        $file_path = $uploads['basedir'] . '/' . $relative_file_path;
        $file_path = $relative_file_path;	
        if($file_path){
            error_log("path is .".print_r($file_path,true));
                    $s3_url = offload_file_to_s3($file_path, $s3_bucket, $s3_key, $s3_secret, $s3_region);
                    error_log("url is .".print_r($s3_url,true));

                    if ($s3_url != "" && filter_var($s3_url, FILTER_VALIDATE_URL)) {
                        // Mark the file as offloaded
                        update_post_meta($id, 'offloaded_to_s3', true);

                        // Update the file's URL
                        update_post_meta($id, '_wp_attached_file', $s3_url);
                    }
                    else{
                        error_log("Valid url not received : ".print_r($s3_url,true));
                    }
        }
        
    }

    wp_reset_postdata();

    error_log("Offloading files completed");
}

 
 if (!wp_next_scheduled('offload_files')) {
     wp_schedule_event(time(), 'hourly', 'offload_files');
 }
 
 add_action('offload_files', 'offload_files');
 // old and working but with glitches
//  function schedule_offload_files_on_add_attachment($data, $post_ID) {
//     // Schedule the offload_files_on_add_attachment function to run after 1 minute
//     wp_schedule_single_event(time() + 60, 'offload_files_on_add_attachment_event', array($data, $post_ID));

//     return $data;
// }

// add_filter('wp_update_attachment_metadata', 'schedule_offload_files_on_add_attachment', 10, 2);
// new and improved without glitched but not tested
function schedule_offload_files_on_add_attachment($data, $post_ID) {
    // Check if an event is already scheduled
    if (!wp_next_scheduled('offload_files_on_add_attachment_event', array($data, $post_ID))) {
        // Schedule the offload_files_on_add_attachment function to run after 1 minute
        wp_schedule_single_event(time() + 60, 'offload_files_on_add_attachment_event', array($data, $post_ID));
    }

    return $data;
}

add_filter('wp_update_attachment_metadata', 'schedule_offload_files_on_add_attachment', 10, 2);

function offload_files_on_add_attachment($data, $post_ID) {
    // Get the S3 credentials from the WordPress options
    $s3_bucket = get_option('sa_s3_bucket_name');
    $s3_key = get_option('sa_s3_bucket_key');
    $s3_secret = get_option('sa_s3_bucket_secret');
    $s3_region = get_option('sa_s3_bucket_region');

    $relative_file_path = get_attached_file($post_ID);
    $uploads = wp_get_upload_dir();
    $file_path = $uploads['basedir'] . '/' . $relative_file_path;
    $file_path = $relative_file_path;	
    if($file_path){
        $s3_url = offload_file_to_s3($file_path, $s3_bucket, $s3_key, $s3_secret, $s3_region);

        if ($s3_url != "" && filter_var($s3_url, FILTER_VALIDATE_URL)) {
            // Mark the file as offloaded
            update_post_meta($post_ID, 'offloaded_to_s3', true);

            // Update the file's URL
            update_post_meta($post_ID, '_wp_attached_file', $s3_url);
        }
    }
}

add_action('offload_files_on_add_attachment_event', 'offload_files_on_add_attachment', 10, 2);


add_filter('wp_get_attachment_url', 'get_s3_url', 10, 2);

function get_s3_url($url, $post_id) {
    //echo $post_id." : ".$url;
    $s3_url = get_post_meta($post_id, '_wp_attached_file', true);

    if ($s3_url != "" && filter_var($s3_url, FILTER_VALIDATE_URL)) {
        return $s3_url;
    }

    return $url;
}
add_filter('wp_prepare_attachment_for_js', function ($response, $attachment, $meta) {
    unset($response['sizes']);
    return $response;
}, 10, 3);

// will remove this commented code soon
// add_filter('jpeg_quality', function($arg){return 100;});
// add_filter('intermediate_image_sizes_advanced', 'prefix_remove_default_images');
// function prefix_remove_default_images($sizes) {
//     unset($sizes['small']); // 150px
//     unset($sizes['medium']); // 300px
//     unset($sizes['large']); // 1024px
//     unset($sizes['medium_large']); // 768px
//     return $sizes;
// }

// Remove custom image size
// function remove_custom_image_sizes() {
//     remove_image_size('custom-size');
// }
// add_action('init', 'remove_custom_image_sizes');

// add_filter('wp_get_attachment_image_src', 'remove_size_postfixes_from_image_src', 10, 3);

// function remove_size_postfixes_from_image_src($image, $attachment_id, $size) {
//     print_r($attachment_id);
//     print_r($image);
//     if ($image[0]) {
//         $image[0] = preg_replace('/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $image[0]);
//         $image[0] = "https://heool.com/";
//     }
//     return $image;
// }

// function remove_image_size_attributes($html) {
//     print_r($html);
//     $html = preg_replace('/(width|height)="\d*"\s/', "", $html);
//     return $html;
// }
// add_filter('post_thumbnail_html', 'remove_image_size_attributes');
// add_filter('image_send_to_editor', 'remove_image_size_attributes');



//////// admin page

add_action('admin_menu', 'sa_s3_admin_menu');

function sa_s3_admin_menu() {
    add_options_page('S3 Credentials', 'S3 Credentials', 'manage_options', 's3-credentials', 'sa_s3_credentials_page');
}

function sa_s3_credentials_page() {
    ?>
    <div class="wrap">
        <h1>S3 Credentials</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('sa_s3_credentials');
            do_settings_sections('sa_s3_credentials');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'sa_s3_admin_init');

function sa_s3_admin_init() {
    register_setting('sa_s3_credentials', 'sa_s3_bucket_name');
    register_setting('sa_s3_credentials', 'sa_s3_bucket_key');
    register_setting('sa_s3_credentials', 'sa_s3_bucket_secret');
    register_setting('sa_s3_credentials', 'sa_s3_bucket_region');

    add_settings_section('sa_s3_credentials_section', 'AWS S3 Credentials', null, 'sa_s3_credentials');

    add_settings_field(
        'sa_s3_bucket_region',
        __('S3 Bucket Region', 'sa_s3'),
        'sa_s3_bucket_region_render',
        'sa_s3_credentials',
        'sa_s3_credentials_section'
    );
    
    add_settings_field('sa_s3_bucket_name', 'S3 Bucket Name', 'sa_s3_bucket_name_callback', 'sa_s3_credentials', 'sa_s3_credentials_section');
    add_settings_field('sa_s3_bucket_key', 'S3 Key', 'sa_s3_bucket_key_callback', 'sa_s3_credentials', 'sa_s3_credentials_section');
    add_settings_field('sa_s3_bucket_secret', 'S3 Secret', 'sa_s3_bucket_secret_callback', 'sa_s3_credentials', 'sa_s3_credentials_section');
}
function sa_s3_bucket_region_render() {
    $setting = get_option('sa_s3_bucket_region');
    echo "<input type='text' name='sa_s3_bucket_region' value='" . esc_attr($setting) . "' required >";
}

function sa_s3_bucket_name_callback() {
    echo '<input type="text" name="sa_s3_bucket_name" value="' . esc_attr(get_option('sa_s3_bucket_name')) . '" required />';
}

function sa_s3_bucket_key_callback() {
    echo '<input type="text" name="sa_s3_bucket_key" value="' . esc_attr(get_option('sa_s3_bucket_key')) . '" required />';
}

function sa_s3_bucket_secret_callback() {
    echo '<input type="text" name="sa_s3_bucket_secret" value="' . esc_attr(get_option('sa_s3_bucket_secret')) . '" required />';
}

?>
