<?php extend('master.php') ?>
<?php startblock('extra_head') ?>
<?php endblock() ?>
<?php startblock('page-title') ?>
<?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?>
<?php endblock() ?>
<?php startblock('content') ?>
<?php $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }; ?>
<section class="slice color-three">
	<div class="w-section inverse p-0">
		<div class="col-12">
			<div class="card p-4 mb-4">
				<h3 class="text-dark p-0 mb-3"><?php echo $e($page_title); ?><?php if ($id !== '') { ?> <small class="text-muted"><code>astpp_<?php echo $e($row['key_id']); ?>_&hellip;</code></small><?php } ?></h3>
				<?php if ($error !== '') { ?>
					<div class="alert alert-danger"><?php echo $e($error); ?></div>
				<?php } ?>
				<form method="post" action="<?php echo base_url(); ?>apikeys/<?php echo $id === '' ? 'apikeys_save/' : 'apikeys_update/' . (int) $id . '/'; ?>">
					<input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
					<div class="form-group">
						<label for="apikey_name"><?php echo $e(gettext('Name')); ?> *</label>
						<input type="text" class="form-control" id="apikey_name" name="name" maxlength="100" required value="<?php echo $e($row['name']); ?>" placeholder="<?php echo $e(gettext('e.g. CRM provisioning')); ?>">
					</div>
					<div class="form-group">
						<label for="apikey_ips"><?php echo $e(gettext('Allowed IP addresses')); ?> *</label>
						<textarea class="form-control" id="apikey_ips" name="allowed_ips" rows="4" required style="font-family: monospace;"><?php echo $e($row['allowed_ips']); ?></textarea>
						<small class="form-text text-muted"><?php echo $e(gettext('One IPv4 or IPv6 address or network per line, e.g. 203.0.113.10 or 203.0.113.0/28. The key only works from these addresses.')); ?>
							<?php echo $e(gettext('Your current IP address:')); ?> <code><?php echo $e($client_ip); ?></code></small>
					</div>
					<?php if ($id !== '') { ?>
					<div class="form-group">
						<label for="apikey_status"><?php echo $e(gettext('Status')); ?></label>
						<select class="form-control" id="apikey_status" name="status">
							<option value="0"<?php echo (string) $row['status'] === '0' ? ' selected' : ''; ?>><?php echo $e(gettext('Active')); ?></option>
							<option value="1"<?php echo (string) $row['status'] === '1' ? ' selected' : ''; ?>><?php echo $e(gettext('Disabled')); ?></option>
						</select>
					</div>
					<?php } ?>
					<button type="submit" class="btn btn-success"><?php echo $e($id === '' ? gettext('Create API key') : gettext('Save')); ?></button>
					<a class="btn btn-secondary ml-2" href="<?php echo base_url(); ?>apikeys/apikeys_list/"><?php echo $e(gettext('Cancel')); ?></a>
				</form>
			</div>
		</div>
	</div>
</section>
<?php endblock() ?>
<?php end_extend() ?>
