<?php 

/*
Plugin Name: Same Taxonomy URL in Post-to-Post Links
Description: This plugin checks all &lt;a href&gt; hyperlinks within the content of posts. It ignores external links and only proceeds with internal links to other posts. It checks if the linked posts are in the same taxonomy (Category or Tag) as detected in the current URL path. If yes, it will modifiy the wrong link to match the same URL path. This is to make sure the user stays in the same taxonomy (category or tag) URL parth for navigation purposes.
Author: Erik Molenaar
Version: 1.0
Author URI: http://erikmolenaar.nl
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// The function hooked to the content filter
add_filter( 'the_content', 'emnl_same_taxonomy_url_post_to_post_links' );
function emnl_same_taxonomy_url_post_to_post_links( $content ) {

	// Prevent this function to run mulitple times on a post, each time the_content is accessed by other code/plugins
    if ( ! in_the_loop() || ! is_main_query() ) return $content;

	// Stop if not viewing a post
	if ( ! is_singular() ) return $content;

	// Step 1 - Get the current taxonomy from the current post URL

		echo "<script>console.log ('---------------------------------- STEP 1 ----------------------------------');</script>";

		// Get the current path
		$base_url = get_site_url();
		$full_url = 'http' . ( isset( $_SERVER['HTTPS'] ) ? 's' : '' ) . '://' . "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
		$full_url = rtrim( $full_url, '/' );
		$current_path = str_replace( $base_url, '', $full_url );

		echo "<script>console.log ('Current full URL detected: " . json_encode( $full_url ) . "');</script>";

		// Remove the current post slug from current path
		global $post;
		$post_slug = $post->post_name;
		$current_path = str_replace( $post_slug, '', $current_path );

		// Sanitize the path (by removing prefixed and postfixed slashes)
		$current_path = str_replace( '//', '', $current_path );
		$current_path = ltrim( $current_path, '/' );
		$current_path = rtrim( $current_path, '/' );

		// Extract the current taxonomy from the last /element/ from the URL path (and check its validity to make sure)
		$current_path_array = explode( '/', $current_path );
		$current_path_array = array_reverse( $current_path_array );

		foreach ( $current_path_array as $slug ) {

			// Check if slug is a Category
			if ( get_term_by( 'slug', $slug, 'category' ) ) {

				$taxonomy_current_post = get_term_by( 'slug', $slug, 'category' );
				break;

			// Check if slug is a Tag
			} elseif ( get_term_by( 'slug', $slug, 'post_tag' ) ) {

				$taxonomy_current_post = get_term_by( 'slug', $slug, 'post_tag' );
				break;

			}

		}

		// Stop if no valid taxonomy was extracted from URL. And return unchanged content.
		if ( ! isset( $taxonomy_current_post ) ) return $content;

		echo "<script>console.log ('Current post slug detected in URL: " . json_encode( $post_slug ) . "');</script>";
		echo "<script>console.log ('Best matching taxonomy detected in URL: " . json_encode( $taxonomy_current_post->slug ) . "');</script>";

	// Step 2 - Find all internal post links and place into array: $post_links

		echo "<script>console.log ('---------------------------------- STEP 2 ----------------------------------');</script>";

		// Using DOM to extract
		$dom = new DomDocument();
		$dom->loadHTML( do_shortcode( get_the_content()) ); // do_shortcode is applied to make sure links created by shortcodes (e.g. [caturl] are also expanded

		$post_links = array();

		$loop = 1;

		// Loop thru all a href elements
		foreach ( $dom->getElementsByTagName( 'a' ) as $item ) {
			
			// Get the full a href tag
			$full_tag = $dom->saveXML( $item );

			// Fix for strange character popping up before spaces
			$full_tag = str_replace( 'Â','', $full_tag );

			// Isolating the URL within the a href tag
			$a = new SimpleXMLElement( $full_tag );
			$url = (string) $a['href'];

			// Check if URL is already in array, if yes, skip other tests to speed up performance
			// Disabled it because this rarely happens....

			// $url_post_id = false;
			// $url_in_array = array_search( $url, array_column( $post_links, 'url' ) );
			
			// if ( $url_in_array !== false ) {

			//	$url_post_id = true;
			//	echo "<script>console.log ('ℹ️ This URL is already in array: " . $url_in_array . "');</script>";
				
			// }


			// Making sure it is an internal link by checking if the site base url in it
			if ( strpos( $url, $base_url ) !== false ) {

				// Making sure it is an internal link by checking if the site base url in it
				if ( strpos_array( $url, array( '.png', '.jpg', '.jpeg', '.gif' ) ) === false ) {
			
					$url_post_id = url_to_postid( $url );
			
					if ( $url_post_id !== 0 ) {
		
						$url_post = get_post( $url_post_id ); 
						$url_post_slug = $url_post->post_name;
						
						$post_links[] = array(
							'number' => $loop,
							'url' => $url,
							'full-tag' => $full_tag,
							'post-id' => $url_post_id,
							'post-slug' => $url_post_slug
						);
						
						echo "<script>console.log ('✅ URL #" . $loop . ": " . $url . " resolves to post ID: " . json_encode( $url_post_id ) . "');</script>";

						$loop = $loop + 1;

					} else {
		
						echo "<script>console.log ('❌ No corresponding post ID found! Skipped: " . $url . "');</script>";
		
					}

				} else {

					echo "<script>console.log ('❌ Image link skipped: " . $url . "');</script>";

				}
				
			} else {

				echo "<script>console.log ('❌ Base URL not detected. Could be an external link. Skipped: " . $url . "');</script>";

			}


		}

		// echo "<script>console.log ('post_links: " . json_encode( $post_links ) . "');</script>";

	// Step 3 - Checks if internal post links (from array $post_links) are in same taxonomy. Modify URL if needed.

		echo "<script>console.log ('---------------------------------- STEP 3 ----------------------------------');</script>";

		// Get all taxonomy names (Categories, Tags and more)		
		$taxonomy_names = get_taxonomies( '', 'names' );

		// Check if linked posts in array are in same taxonomy
		foreach ( $post_links as $post_link ) {

			$taxonomies = wp_get_object_terms( $post_link['post-id'], $taxonomy_names );

			// At this moment we presume linked post is not in the same taxonomy
			$in_same_tax = false;

			// Cycle thru all terms to see if it's in the same taxonomy
			foreach ( $taxonomies as $taxonomy ) {

				if ( $taxonomy->slug === $taxonomy_current_post->slug ) {

					$in_same_tax = true;
					break; // We found a match. We can stop this loop!

				}

			}

			if ( $in_same_tax ) {

				$new_path = $current_path . '/' . $post_link['post-slug'];
				$new_url = $base_url . '/' . $new_path;

				// Add query string (if there is one)
				$query_string_pos = strpos( $post_link['url'], "?" );
				if ( $query_string_pos !== false ) {
	
					$query_string = substr( $post_link['url'], $query_string_pos);
					$new_url .= $query_string;

				}

				// Add fragment string (if there is one)
				$fragment_string_pos = strpos( $post_link['url'], "#" );
				if ( $fragment_string_pos !== false ) {
	
					$fragment_string = substr( $post_link['url'], $fragment_string_pos);
					$new_url .= $fragment_string;

				}

				// Just a quick final check to see newly constructed URL is correct by checking it actually resolves to a post
				if ( url_to_postid( $new_url ) ) {

					// Check if the constructed URL is actually different from the old post URL
					if ( $post_link['url'] !== $new_url ) {
					
						$old_full_tag = $post_link['full-tag'];

						// Replace the new URL within the <a href> element
						$new_full_tag = str_replace( $post_link['url'], $new_url, $old_full_tag );

						// Replace the new <a href> element within the $content
						$content = str_replace( $old_full_tag, $new_full_tag, $content );

						echo "<script>console.log ('✅ URL #" . json_encode( $post_link['number'] ) . " changed to: " . $new_url . "');</script>";

					} else {

						echo "<script>console.log ('❌ URL #" . json_encode( $post_link['number'] ) . " has NOT been changed! It already has a correct URL...');</script>";

					}

				} else {

					echo "<script>console.log ('❌ URL #" . json_encode( $post_link['number'] ) . " has NOT been changed! Warning: could be a BUG as plugin created an incorrect URL which does not resolve to a existing post ID.');</script>";

				}

			} else {

				echo "<script>console.log ('❌ URL #" . json_encode( $post_link['number'] ) . " has NOT been changed! Post ID is not within the same taxonomy as current URL path.');</script>";

			}

		}

	// Remove filter after current function has been called (to prevent it firing multiple times when 'the_content' is called. This saves resources!)
	remove_filter( current_filter(), __FUNCTION__ );

	// Always return content at end of function
	return $content;

}

// Function to find $needles (array) in $haystack (string)
function strpos_array( $haystack, $needles, $offset = 0 ) {

    if ( is_array( $needles ) ) {

        foreach ( $needles as $needle ) {

            $pos = strpos_array( $haystack, $needle );
			
			if ( $pos !== false ) {

				return $pos;
				
			}
			
		}
		
		return false;
		
    } else {

		return strpos( $haystack, $needles, $offset );
		
	}
	
}