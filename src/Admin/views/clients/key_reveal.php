<?php /** @var array $client @var string $api_key @var bool|null $regenerated @var string|null $webhook_secret */
?>
<h1>API key</h1>
<section class="card slim">
  <h2><?= $regenerated ? 'API key regenerated — ' : 'API key created — ' ?>for “<?= e((string)$client['name']) ?>”</h2>
  <p class="hint key-line"><?= $regenerated ? 'The previous key stopped working immediately.' : 'This key is shown exactly once and cannot be recovered.' ?></p>
  <p><code class="key"><?= e($api_key) ?></code></p>
  <?php if (!empty($webhook_secret)): ?>
  <h2>Auto-generated webhook secret (also shown only now)</h2>
  <p><code class="key"><?= e((string)$webhook_secret) ?></code></p>
  <?php endif; ?>
  <div class="form-ops">
    <a class="btn" href="/admin/clients">Back to clients</a>
  </div>
</section>
