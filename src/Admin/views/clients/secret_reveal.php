<?php /** @var array $client @var string $webhook_secret */
?>
<h1>Webhook secret</h1>
<section class="card slim">
  <h2>New webhook secret — for “<?= e((string)$client['name']) ?>”</h2>
  <p class="hint key-line">Shown exactly once and cannot be recovered. Update the client system now; signatures made with the old secret stop verifying immediately.</p>
  <p><code class="key"><?= e($webhook_secret) ?></code></p>
  <div class="form-ops">
    <a class="btn" href="/admin/clients/<?= (int)$client['id'] ?>">Back to client</a>
    <a class="btn" href="/admin/clients">All clients</a>
  </div>
</section>
