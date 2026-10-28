<?php
/**
 * Tests for the preloading of the author of the post summary.
 *
 * @package gutenberg
 *
 * @covers ::gutenberg_block_editor_preload_paths_7_2
 * @covers ::gutenberg_rest_user_query_map_who_authors
 */
class Tests_Preload_Post_Author extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	protected static $author_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$author_id     = $factory->user->create( array( 'role' => 'author' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Returns the preload paths of the post editor for a post.
	 *
	 * @param WP_Post $post The edited post.
	 * @return array The preload paths.
	 */
	private function get_post_editor_paths( $post ) {
		$context = new WP_Block_Editor_Context(
			array(
				'name' => 'core/edit-post',
				'post' => $post,
			)
		);

		return gutenberg_block_editor_preload_paths_7_2( array(), $context );
	}

	/**
	 * The post editor preloads the authors query of the author field for the
	 * users who can assign authors.
	 */
	public function test_the_post_editor_preloads_the_author() {
		wp_set_current_user( self::$editor_id );
		$post = self::factory()->post->create_and_get( array( 'post_author' => self::$author_id ) );

		$this->assertContains(
			sprintf( '/wp/v2/users?context=view&include[0]=%d&who=authors', self::$author_id ),
			$this->get_post_editor_paths( $post )
		);
	}

	/**
	 * The author field is hidden from the users who can't assign authors, so
	 * nothing requests the author.
	 */
	public function test_the_post_editor_does_not_preload_the_author_for_users_who_cannot_assign_authors() {
		wp_set_current_user( self::$author_id );
		$post = self::factory()->post->create_and_get( array( 'post_author' => self::$author_id ) );

		$this->assertNotContains(
			sprintf( '/wp/v2/users?context=view&include[0]=%d&who=authors', self::$author_id ),
			$this->get_post_editor_paths( $post )
		);
	}

	/**
	 * The preloaded path serves the author, without the deprecation notice of
	 * `who` in `WP_User_Query`.
	 */
	public function test_the_preloaded_author_path_serves_the_author() {
		wp_set_current_user( self::$editor_id );
		$path = sprintf( '/wp/v2/users?context=view&include[0]=%d&who=authors', self::$author_id );

		$preloaded = array_reduce( array( $path ), 'rest_preload_api_request', array() );

		$this->assertSame( array( self::$author_id ), wp_list_pluck( $preloaded[ $path ]['body'], 'id' ) );
	}

	/**
	 * The `who=authors` query still excludes the users with no user level.
	 */
	public function test_the_authors_query_excludes_subscribers() {
		wp_set_current_user( self::$editor_id );
		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_query_params(
			array(
				'who'     => 'authors',
				'include' => array( self::$author_id, self::$subscriber_id ),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( array( self::$author_id ), wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * The `who` argument is mapped to the meta query of the user level, and
	 * merged with any other meta query.
	 */
	public function test_who_authors_is_mapped_to_a_meta_query() {
		global $wpdb;

		$args = gutenberg_rest_user_query_map_who_authors(
			array(
				'who'        => 'authors',
				'meta_query' => array(
					array(
						'key'   => 'foo',
						'value' => 'bar',
					),
				),
			)
		);

		$this->assertArrayNotHasKey( 'who', $args );
		$this->assertSame(
			array(
				'relation' => 'AND',
				array(
					array(
						'key'   => 'foo',
						'value' => 'bar',
					),
				),
				array(
					'key'     => $wpdb->get_blog_prefix() . 'user_level',
					'value'   => 0,
					'compare' => '!=',
				),
			),
			$args['meta_query']
		);
	}
}
