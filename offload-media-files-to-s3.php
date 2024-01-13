<?php
/**
 * Plugin Name: WP S3 Uploader by sahil
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
 
 function offload_file_to_s3($file_path, $s3_bucket, $s3_key, $s3_secret) {
     error_log("Offloading file: " . $file_path);
 
     // Instantiate the client.
     $s3 = new S3Client([
         'version' => 'latest',
         'region'  => 'ap-south-1',
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
        //$file_path = $post->guid;
        $file_path = get_attached_file($id);
        if($file_path){
            error_log("path is .".print_r($file_path,true));
                    $s3_url = offload_file_to_s3($file_path, $s3_bucket, $s3_key, $s3_secret);
                    error_log("url is .".print_r($s3_url,true));

                    if ($s3_url) {
                        // Mark the file as offloaded
                        update_post_meta($id, 'offloaded_to_s3', true);

                        // Update the file's URL
                        update_post_meta($id, '_wp_attached_file', $s3_url);
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

    add_settings_section('sa_s3_credentials_section', 'AWS S3 Credentials', null, 'sa_s3_credentials');

    add_settings_field('sa_s3_bucket_name', 'S3 Bucket Name', 'sa_s3_bucket_name_callback', 'sa_s3_credentials', 'sa_s3_credentials_section');
    add_settings_field('sa_s3_bucket_key', 'S3 Key', 'sa_s3_bucket_key_callback', 'sa_s3_credentials', 'sa_s3_credentials_section');
    add_settings_field('sa_s3_bucket_secret', 'S3 Secret', 'sa_s3_bucket_secret_callback', 'sa_s3_credentials', 'sa_s3_credentials_section');
}

function sa_s3_bucket_name_callback() {
    echo '<input type="text" name="sa_s3_bucket_name" value="' . esc_attr(get_option('sa_s3_bucket_name')) . '" />';
}

function sa_s3_bucket_key_callback() {
    echo '<input type="text" name="sa_s3_bucket_key" value="' . esc_attr(get_option('sa_s3_bucket_key')) . '" />';
}

function sa_s3_bucket_secret_callback() {
    echo '<input type="text" name="sa_s3_bucket_secret" value="' . esc_attr(get_option('sa_s3_bucket_secret')) . '" />';
}

?>


