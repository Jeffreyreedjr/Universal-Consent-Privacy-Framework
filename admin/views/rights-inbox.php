<?php
/**
 * Rights inbox — DSAR / DNS fulfillment queue.
 *
 * @package UCPF
 * @var array $requests
 * @var array $suppress_jobs
 */

defined( 'ABSPATH' ) || exit;

$requests      = isset( $requests ) && is_array( $requests ) ? $requests : array();
$suppress_jobs = isset( $suppress_jobs ) && is_array( $suppress_jobs ) ? $suppress_jobs : array();

$jobs_by_request = array();
foreach ( $suppress_jobs as $job ) {
	$rid = isset( $job['request_id'] ) ? (int) $job['request_id'] : 0;
	if ( $rid > 0 ) {
		if ( ! isset( $jobs_by_request[ $rid ] ) ) {
			$jobs_by_request[ $rid ] = array();
		}
		$jobs_by_request[ $rid ][] = $job;
	}
}
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Rights Inbox', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Operational queue for data subject and Do Not Sell requests. Status updates are for your team’s fulfillment workflow — not a legal determination.', 'universal-consent-privacy-framework' ); ?>
	</p>

	<div class="ucpf-table-scroll">
		<table class="widefat striped ucpf-rights-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Status', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Created', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Scope', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $requests ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No requests yet.', 'universal-consent-privacy-framework' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $requests as $row ) : ?>
						<tr data-ucpf-rights-id="<?php echo esc_attr( (string) $row['id'] ); ?>">
							<td><?php echo esc_html( (string) $row['id'] ); ?></td>
							<td><?php echo esc_html( (string) $row['request_type'] ); ?></td>
							<td>
								<select class="ucpf-rights-status">
									<?php foreach ( \UCPF\Rights_Inbox::STATUSES as $st ) : ?>
										<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $row['status'], $st ); ?>><?php echo esc_html( $st ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><?php echo esc_html( (string) $row['scope'] ); ?></td>
							<td>
								<textarea class="ucpf-rights-notes" rows="2" cols="28"><?php echo esc_textarea( (string) $row['notes'] ); ?></textarea>
								<?php
								$rid = (int) $row['id'];
								if ( ! empty( $jobs_by_request[ $rid ] ) ) :
									?>
									<p class="description" style="margin-top:6px;">
										<?php
										$bits = array();
										foreach ( $jobs_by_request[ $rid ] as $lj ) {
											$bits[] = sprintf(
												'%s (%s)',
												isset( $lj['vendor'] ) ? $lj['vendor'] : '',
												isset( $lj['status'] ) ? $lj['status'] : ''
											);
										}
										echo esc_html( __( 'Suppress jobs:', 'universal-consent-privacy-framework' ) . ' ' . implode( ', ', $bits ) );
										?>
									</p>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button button-primary ucpf-rights-save"><?php esc_html_e( 'Save', 'universal-consent-privacy-framework' ); ?></button>
								<label style="display:block;margin-top:6px;">
									<input type="checkbox" class="ucpf-rights-verified" />
									<?php esc_html_e( 'Mark verified', 'universal-consent-privacy-framework' ); ?>
								</label>
								<?php if ( ! empty( $row['checklist'] ) && is_array( $row['checklist'] ) ) : ?>
									<details style="margin-top:8px;">
										<summary><?php esc_html_e( 'Processor checklist', 'universal-consent-privacy-framework' ); ?></summary>
										<?php foreach ( $row['checklist'] as $ck => $cv ) : ?>
											<label style="display:block;">
												<input type="checkbox" class="ucpf-rights-check" data-key="<?php echo esc_attr( $ck ); ?>" <?php checked( $cv ); ?> />
												<?php echo esc_html( $ck ); ?>
											</label>
										<?php endforeach; ?>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<div class="ucpf-card" style="margin-top:2rem;padding:1rem 1.25rem;background:#fff;border:1px solid #c3c4c7;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Vendor suppress queue', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Jobs queued when a Do Not Sell / privacy opt-out fires. Mark complete only after you confirm CRM/ads suppression in the vendor console or API. Completing a row is an ops acknowledgment — not a legal determination that every processor has stopped.', 'universal-consent-privacy-framework' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="ucpf-suppress-clear-done"><?php esc_html_e( 'Clear completed', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-suppress-clear-all"><?php esc_html_e( 'Clear all', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<div class="ucpf-table-scroll">
			<table class="widefat striped" id="ucpf-suppress-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Vendor', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Request ID', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Queued', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Deny flags', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Status', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $suppress_jobs ) ) : ?>
						<tr class="ucpf-suppress-empty"><td colspan="6"><?php esc_html_e( 'No suppress jobs yet.', 'universal-consent-privacy-framework' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $suppress_jobs as $job ) : ?>
							<tr data-ucpf-suppress-index="<?php echo esc_attr( (string) ( isset( $job['index'] ) ? $job['index'] : '' ) ); ?>">
								<td><code><?php echo esc_html( isset( $job['vendor'] ) ? $job['vendor'] : '' ); ?></code></td>
								<td><?php echo esc_html( isset( $job['request_id'] ) ? (string) $job['request_id'] : '0' ); ?></td>
								<td><?php echo esc_html( ! empty( $job['queued_at'] ) ? gmdate( 'Y-m-d H:i', (int) $job['queued_at'] ) . ' UTC' : '' ); ?></td>
								<td><code><?php echo esc_html( ! empty( $job['deny'] ) && is_array( $job['deny'] ) ? implode( ', ', $job['deny'] ) : '' ); ?></code></td>
								<td class="ucpf-suppress-status"><?php echo esc_html( isset( $job['status'] ) ? $job['status'] : '' ); ?></td>
								<td>
									<?php if ( empty( $job['status'] ) || 'queued' === $job['status'] || 'failed' === $job['status'] ) : ?>
										<button type="button" class="button button-primary ucpf-suppress-complete"><?php esc_html_e( 'Mark complete', 'universal-consent-privacy-framework' ); ?></button>
										<button type="button" class="button ucpf-suppress-skip"><?php esc_html_e( 'Skip', 'universal-consent-privacy-framework' ); ?></button>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<p class="description" id="ucpf-suppress-status-msg" hidden></p>
	</div>
</div>
<script>
(function () {
  if (!window.ucpfAdmin) return;
  document.querySelectorAll('[data-ucpf-rights-id]').forEach(function (row) {
    var btn = row.querySelector('.ucpf-rights-save');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var id = row.getAttribute('data-ucpf-rights-id');
      var checklist = {};
      row.querySelectorAll('.ucpf-rights-check').forEach(function (el) {
        checklist[el.getAttribute('data-key')] = !!el.checked;
      });
      var body = {
        status: (row.querySelector('.ucpf-rights-status') || {}).value,
        notes: (row.querySelector('.ucpf-rights-notes') || {}).value || '',
        checklist: checklist,
        mark_verified: !!(row.querySelector('.ucpf-rights-verified') || {}).checked
      };
      fetch(ucpfAdmin.restUrl + 'rights-inbox/' + id, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ucpfAdmin.nonce },
        credentials: 'same-origin',
        body: JSON.stringify(body)
      }).then(function (r) { return r.json(); }).then(function () {
        btn.textContent = 'Saved';
        setTimeout(function () { btn.textContent = 'Save'; }, 1200);
      });
    });
  });

  var msg = document.getElementById('ucpf-suppress-status-msg');
  function flash(t) {
    if (!msg) return;
    msg.hidden = false;
    msg.textContent = t;
  }
  function setStatus(index, status) {
    return fetch(ucpfAdmin.restUrl + 'vendor-suppress-queue/' + index, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ucpfAdmin.nonce },
      credentials: 'same-origin',
      body: JSON.stringify({ status: status })
    }).then(function (r) { return r.json(); });
  }
  document.querySelectorAll('[data-ucpf-suppress-index]').forEach(function (row) {
    var idx = row.getAttribute('data-ucpf-suppress-index');
    var complete = row.querySelector('.ucpf-suppress-complete');
    var skip = row.querySelector('.ucpf-suppress-skip');
    if (complete) {
      complete.addEventListener('click', function () {
        setStatus(idx, 'completed').then(function () {
          row.querySelector('.ucpf-suppress-status').textContent = 'completed';
          flash('Marked complete (ops confirmation only).');
          complete.remove();
          if (skip) skip.remove();
        });
      });
    }
    if (skip) {
      skip.addEventListener('click', function () {
        setStatus(idx, 'skipped').then(function () {
          row.querySelector('.ucpf-suppress-status').textContent = 'skipped';
          flash('Skipped.');
          if (complete) complete.remove();
          skip.remove();
        });
      });
    }
  });
  var clearDone = document.getElementById('ucpf-suppress-clear-done');
  var clearAll = document.getElementById('ucpf-suppress-clear-all');
  if (clearDone) {
    clearDone.addEventListener('click', function () {
      fetch(ucpfAdmin.restUrl + 'vendor-suppress-queue?completed_only=1', {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': ucpfAdmin.nonce },
        credentials: 'same-origin'
      }).then(function () { location.reload(); });
    });
  }
  if (clearAll) {
    clearAll.addEventListener('click', function () {
      if (!window.confirm('Clear the entire vendor suppress queue?')) return;
      fetch(ucpfAdmin.restUrl + 'vendor-suppress-queue', {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': ucpfAdmin.nonce },
        credentials: 'same-origin'
      }).then(function () { location.reload(); });
    });
  }
})();
</script>
