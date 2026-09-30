<?php
/**
 * Content Publisher > Pipeline test: run one file through the pipeline and
 * see what it produces. Nothing is saved or sent anywhere.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Pipeline\FidelityException;
use CPub\Publisher\Pipeline\Llm\LlmException;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\Pipeline;
use CPub\Publisher\Pipeline\StructureException;
use CPub\Publisher\Settings\AiSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PipelinePage {

	public const SLUG  = 'cpub-publisher-pipeline';
	public const NONCE = 'cpub_pipeline_test';

	private static function samples(): array {
		$dir = dirname( __DIR__, 2 ) . '/samples/';
		$out = array();
		foreach ( glob( $dir . '*.txt' ) ?: array() as $f ) {
			$out[ basename( $f ) ] = $f;
		}
		return $out;
	}

	/** @return array{result?:array, error?:string, name?:string} */
	private function handle(): array {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['cpub_run'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return array();
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( 'Only Content Publisher managers can use the pipeline test.', 403 );
		}
		check_admin_referer( self::NONCE );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // AI calls with retries can take a while
		}
		wp_raise_memory_limit( 'admin' );
		$map  = null;
		$name = '';
		if ( ! empty( $_FILES['cpub_file']['tmp_name'] ) && is_uploaded_file( $_FILES['cpub_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$name = sanitize_file_name( wp_unslash( $_FILES['cpub_file']['name'] ?? 'upload.txt' ) );
			if ( ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array( 'txt', 'md', 'docx' ), true ) ) {
				return array( 'error' => 'Choose a .txt, .md or .docx file.' );
			}
			$bytes = (string) file_get_contents( $_FILES['cpub_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.WP.AlternativeFunctions
		} else {
			$samples = self::samples();
			$name    = sanitize_file_name( wp_unslash( $_POST['cpub_sample'] ?? '' ) );
			if ( ! isset( $samples[ $name ] ) ) {
				return array( 'error' => 'Choose a file to upload or one of the samples.' );
			}
			$bytes = (string) file_get_contents( $samples[ $name ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! empty( $_POST['cpub_recorded'] ) ) {
				$map = json_decode( (string) file_get_contents( substr( $samples[ $name ], 0, -4 ) . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		try {
			return array( 'result' => ( new Pipeline( AiSettings::provider() ) )->run( $bytes, $name, $map ), 'name' => $name );
		} catch ( StructureException | FidelityException | LlmException | \InvalidArgumentException $e ) {
			return array( 'error' => $e->getMessage(), 'name' => $name );
		} catch ( \Throwable $e ) {
			error_log( 'Content Publisher pipeline test: ' . $e ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return array( 'error' => 'Unexpected error (details are in the PHP error log): ' . $e->getMessage(), 'name' => $name );
		}
	}

	public function render(): void {
		$state = $this->handle();
		$ai    = AiSettings::get();
		?>
		<div class="wrap" id="cpub-pipeline">
			<h1>Pipeline test</h1>
			<p>Run a post through the pipeline and see the result. Nothing is saved or sent to any client site.</p>
			<?php if ( ! $ai['has_key'] ) : ?>
				<div class="notice notice-warning inline"><p>No AI key is set, so only the recorded structures for the samples and .md files work. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ); ?>">Add a key under Settings</a>.</p></div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;max-width:900px">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p><label><strong>Upload a file</strong> (.txt, .md or .docx): <input type="file" name="cpub_file" id="cpub-file" accept=".txt,.md,.docx,text/plain,text/markdown,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></label></p>
				<p><strong>or use a sample:</strong>
					<select name="cpub_sample" id="cpub-sample"><option value="">—</option>
						<?php foreach ( array_keys( self::samples() ) as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $state['name'] ?? '', $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select>
					<label style="margin-left:12px"><input type="checkbox" name="cpub_recorded" id="cpub-recorded" value="1" <?php checked( ! empty( $_POST['cpub_recorded'] ) ); // phpcs:ignore WordPress.Security.NonceVerification ?>> use its recorded structure instead of calling the AI</label>
				</p>
				<p><button type="submit" name="cpub_run" value="1" class="button button-primary" id="cpub-run">Run</button>
					<span class="description" style="margin-left:8px">AI: <?php echo esc_html( $ai['has_key'] ? AiSettings::PROVIDERS[ $ai['provider'] ]['label'] . ' · ' . $ai['model'] : 'none' ); ?>. With the AI this can take up to a minute.</span></p>
			</form>
			<?php
			if ( isset( $state['error'] ) ) {
				echo '<div class="notice notice-error" id="cpub-pipeline-error"><p>' . nl2br( esc_html( $state['error'] ) ) . '</p></div>';
			}
			if ( isset( $state['result'] ) ) {
				$this->render_result( $state['result'] );
			}
			?>
		</div>
		<?php
	}

	private function render_result( array $r ): void {
		$post   = $r['post'];
		$tokens = array_sum( array_intersect_key( $r['usage'], array_flip( array( 'total_tokens', 'input_tokens', 'output_tokens', 'total_input_tokens', 'total_output_tokens' ) ) ) );
		$counts = implode( ', ', array_map( fn( $k, $v ) => "{$v} {$k}", array_keys( $post['counts'] ), $post['counts'] ) );
		?>
		<h2 id="cpub-result" data-ai="<?php echo $r['ai'] ? '1' : '0'; ?>" data-attempts="<?php echo (int) $r['attempts']; ?>" data-errors="<?php echo count( $post['errors'] ); ?>">Result: <?php echo esc_html( $r['name'] ); ?></h2>
		<p><?php echo esc_html( $r['ai'] ? sprintf( 'Structured by the AI in %d attempt(s)%s.', $r['attempts'], $tokens ? ", about {$tokens} tokens" : '' ) : ( null !== $r['map'] ? 'Recorded structure used (no AI call).' : 'No AI call.' ) ); ?>
			<?php echo esc_html( "Encoding: {$r['encoding']}. {$counts}." ); ?>
			<?php if ( null !== $r['map'] ) : ?>
				<strong style="color:#00a32a" id="cpub-fidelity">Fidelity check passed: the text is exactly the original.</strong>
			<?php else : ?>
				<span id="cpub-fidelity">Markdown file: used as written (no AI, nothing to check it against).</span>
			<?php endif; ?></p>

		<?php if ( $r['warnings'] || $post['errors'] ) : ?>
			<div class="notice notice-warning inline" id="cpub-warnings"><p><strong>For the reviewer:</strong></p><ul style="list-style:disc;padding-left:2em">
				<?php foreach ( $post['errors'] as $e ) : ?>
					<li><?php echo esc_html( ( $e['line'] ? "Markdown line {$e['line']}: " : '' ) . $e['message'] ); ?></li>
				<?php endforeach; ?>
				<?php foreach ( $r['warnings'] as $w ) : ?>
					<li><?php echo esc_html( $w ); ?></li>
				<?php endforeach; ?>
			</ul></div>
		<?php endif; ?>

		<?php if ( $r['removed'] ) : ?>
			<p id="cpub-removed"><strong>Lines left out of the post:</strong> <?php echo esc_html( implode( ' · ', array_map( fn( $x ) => "{$x['kind']}: {$x['text']}", $r['removed'] ) ) ); ?></p>
		<?php endif; ?>

		<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
			<div style="flex:1 1 420px;min-width:320px">
				<h3>Markdown (what the reviewer will edit)</h3>
				<textarea readonly class="large-text code" rows="30" id="cpub-markdown"><?php echo esc_textarea( $r['markdown'] ); ?></textarea>
			</div>
			<div style="flex:1 1 420px;min-width:320px">
				<h3>Preview</h3>
				<div id="cpub-preview" style="background:#fff;border:1px solid #c3c4c7;padding:8px 24px;max-height:640px;overflow:auto">
					<h1><?php echo esc_html( $post['title'] ); ?></h1>
					<?php echo wp_kses_post( $post['content'] ); ?>
				</div>
			</div>
		</div>
		<details style="margin-top:16px"><summary>WordPress block markup</summary>
			<textarea readonly class="large-text code" rows="20" id="cpub-blocks"><?php echo esc_textarea( MarkdownToPost::convert( $r['markdown'] )['content'] ); ?></textarea>
		</details>
		<?php if ( $r['map'] ) : ?>
			<details><summary>Structure map (the AI's answer)</summary>
				<textarea readonly class="large-text code" rows="16"><?php echo esc_textarea( wp_json_encode( $r['map'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
			</details>
		<?php endif; ?>
		<style>#cpub-preview table{border-collapse:collapse;width:100%}#cpub-preview td,#cpub-preview th{border:1px solid #dcdcde;padding:4px 8px}#cpub-preview img{max-width:100%;height:auto}#cpub-preview figcaption{font-size:12px;color:#646970}#cpub-preview ul{list-style:disc;padding-left:2em}#cpub-preview ol{list-style:decimal;padding-left:2em}</style>
		<?php
	}
}
