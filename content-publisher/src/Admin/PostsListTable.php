<?php
/**
 * The list on Content Publisher > Posts.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Review;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class PostsListTable extends \WP_List_Table {

	/** @var array<int,object> site id => site */
	private array $sites = array();

	public function __construct() {
		parent::__construct( array( 'singular' => 'post', 'plural' => 'posts', 'ajax' => false ) );
		foreach ( Sites::all() as $s ) {
			$this->sites[ (int) $s->id ] = $s;
		}
	}

	private function arg( string $key ): string {
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public function get_columns(): array {
		return array(
			'title'      => 'Title',
			'site'       => 'Client site',
			'status'     => 'Status',
			'notes'      => 'Notes',
			'created_by' => 'Uploaded by',
			'created_at' => 'Uploaded',
		);
	}

	protected function get_sortable_columns(): array {
		return array( 'title' => array( 'title', false ), 'status' => array( 'status', false ), 'created_at' => array( 'created_at', true ) );
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$per_page              = 20;
		$status                = $this->arg( 'status' );
		[ $rows, $total ]      = Jobs::query(
			array(
				'status'   => '' !== $status ? $status : null,
				'site_id'  => (int) $this->arg( 'site' ),
				'batch_id' => sanitize_key( $this->arg( 'batch' ) ),
				'search'   => $this->arg( 's' ),
				'orderby'  => $this->arg( 'orderby' ),
				'order'    => $this->arg( 'order' ),
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);
		$this->items = $rows;
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per_page ) );
	}

	/** Jobs on this page still waiting or being processed. @return int[] */
	public function pending_ids(): array {
		return array_values( array_map( fn( $j ) => (int) $j->id, array_filter( $this->items, fn( $j ) => in_array( $j->status, array( Jobs::QUEUED, Jobs::PROCESSING, Jobs::APPROVED, Jobs::SENDING ), true ) ) ) );
	}

	protected function get_views(): array {
		$counts  = Jobs::counts( (int) $this->arg( 'site' ) ?: null );
		$current = $this->arg( 'status' );
		$base    = remove_query_arg( array( 'status', 'paged', 'batch' ) );
		$views   = array( 'all' => sprintf( '<a href="%s"%s>All <span class="count">(%d)</span></a>', esc_url( $base ), '' === $current ? ' class="current" aria-current="page"' : '', array_sum( $counts ) ) );
		foreach ( Jobs::LABELS as $status => $label ) {
			if ( $counts[ $status ] || $current === $status ) {
				$views[ $status ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', $status, $base ) ), $current === $status ? ' class="current" aria-current="page"' : '', esc_html( $label ), $counts[ $status ] );
			}
		}
		return $views;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || count( $this->sites ) < 2 ) {
			return;
		}
		$current = (int) $this->arg( 'site' );
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="cpub-site-filter">Client site</label><select name="site" id="cpub-site-filter"><option value="">All client sites</option>';
		foreach ( $this->sites as $id => $s ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $current, $id, false ), esc_html( $s->name ?: $s->url ) );
		}
		echo '</select>';
		submit_button( 'Filter', '', 'filter_action', false );
		echo '</div>';
	}

	public function no_items(): void {
		echo 'No posts here yet. ';
		printf( '<a href="%s">Add posts</a>.', esc_url( admin_url( 'admin.php?page=' . AddPostsPage::SLUG ) ) );
	}

	protected function column_title( $job ): string {
		$open    = EditorPage::url( (int) $job->id );
		$actions = array();
		$can     = Review::can( $job );
		if ( ! in_array( $job->status, array( Jobs::QUEUED, Jobs::PROCESSING ), true ) ) {
			$actions['open'] = sprintf( '<a href="%s">%s</a>', esc_url( $open ), Jobs::REVIEW === $job->status ? 'Review' : 'Open' );
		}
		if ( $can['retry'] ) {
			$actions['retry'] = sprintf( '<a href="%s">Retry</a>', esc_url( PostsPage::action_url( 'cpub_job_retry', (int) $job->id ) ) );
		}
		if ( $can['send'] ) {
			$actions['send'] = sprintf( '<a href="%s">Send again</a>', esc_url( PostsPage::action_url( 'cpub_job_send', (int) $job->id ) ) );
		}
		if ( ! empty( $job->data['sent']['edit_url'] ) && in_array( $job->status, array( Jobs::SENT, Jobs::PUBLISHED ), true ) ) {
			$actions['client'] = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( Jobs::PUBLISHED === $job->status && ! empty( $job->data['published_link'] ) ? $job->data['published_link'] : $job->data['sent']['edit_url'] ), Jobs::PUBLISHED === $job->status ? 'View on the client site' : 'Open the draft on the client site' );
		}
		if ( $can['plain'] ) {
			$actions['plain'] = sprintf( '<a href="%s">Set out without the AI</a>', esc_url( PostsPage::action_url( 'cpub_job_plain', (int) $job->id ) ) );
		}
		if ( $can['delete'] ) {
			$actions['delete'] = sprintf( '<a href="%s" class="submitdelete" onclick="return confirm(%s)">Delete</a>', esc_url( PostsPage::action_url( 'cpub_job_delete', (int) $job->id ) ), esc_attr( wp_json_encode( 'Delete this post and its files? This can’t be undone.' ) ) );
		}
		$title = in_array( $job->status, array( Jobs::QUEUED, Jobs::PROCESSING ), true )
			? '<strong>' . esc_html( $job->title ) . '</strong>'
			: sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( $open ), esc_html( $job->title ) );
		return $title . '<br><span class="description">' . esc_html( $job->source_name ) . '</span>' . $this->row_actions( $actions );
	}

	protected function column_site( $job ): string {
		$s = $this->sites[ (int) $job->site_id ] ?? null;
		return $s ? esc_html( $s->name ?: $s->url ) : '<em>Removed site</em>';
	}

	protected function column_status( $job ): string {
		$colors = array(
			Jobs::QUEUED     => '#646970',
			Jobs::PROCESSING => '#2271b1',
			Jobs::REVIEW     => '#b26200',
			Jobs::FAILED     => '#d63638',
			Jobs::APPROVED    => '#2271b1',
			Jobs::REJECTED    => '#50575e',
			Jobs::SENDING     => '#2271b1',
			Jobs::SENT        => '#00a32a',
			Jobs::SEND_FAILED => '#d63638',
			Jobs::PUBLISHED   => '#00631e',
		);
		$label  = Jobs::label( $job );
		$extra  = in_array( $job->status, array( Jobs::QUEUED, Jobs::APPROVED ), true ) && $job->last_error ? '<br><span class="description">Waiting to retry</span>' : '';
		return sprintf( '<span class="cpub-status cpub-status-%1$s" style="color:%2$s;font-weight:600">%3$s</span>%4$s', esc_attr( $job->status ), esc_attr( $colors[ $job->status ] ?? '#000' ), esc_html( $label ), $extra );
	}

	protected function column_notes( $job ): string {
		if ( in_array( $job->status, array( Jobs::FAILED, Jobs::SEND_FAILED ), true ) || ( in_array( $job->status, array( Jobs::QUEUED, Jobs::APPROVED ), true ) && $job->last_error ) ) {
			return '<span style="color:#d63638">' . esc_html( wp_trim_words( (string) $job->last_error, 30 ) ) . '</span>';
		}
		if ( Jobs::REJECTED === $job->status ) {
			return esc_html( wp_trim_words( (string) $job->review_note, 30 ) );
		}
		if ( Jobs::SENT === $job->status && ! empty( $job->data['sent']['tags_missing'] ) ) {
			return esc_html( 'Tags not on the site: ' . implode( ', ', $job->data['sent']['tags_missing'] ) );
		}
		if ( ! empty( $job->data['client_deleted'] ) && Jobs::SENT === $job->status ) {
			return esc_html( 'The draft was deleted on the client site.' );
		}
		if ( in_array( $job->status, array( Jobs::SENT, Jobs::PUBLISHED ), true ) ) {
			return '';
		}
		$n = count( $job->warnings );
		return $n ? esc_html( sprintf( _n( '%d note for the reviewer', '%d notes for the reviewer', $n ), $n ) ) : '';
	}

	protected function column_created_by( $job ): string {
		$u = get_userdata( (int) $job->created_by );
		return $u ? esc_html( $u->display_name ) : '';
	}

	protected function column_created_at( $job ): string {
		$t = strtotime( $job->created_at . ' UTC' );
		return sprintf( '<time datetime="%s" title="%s">%s ago</time>', esc_attr( gmdate( 'c', $t ) ), esc_attr( get_date_from_gmt( $job->created_at, 'j M Y, H:i' ) ), esc_html( human_time_diff( $t ) ) );
	}
}
