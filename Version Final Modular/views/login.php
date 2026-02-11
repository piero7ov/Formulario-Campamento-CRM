<div class="login-wrapper">
  <form method="post" class="login-card">
    <div class="logo">
      <img src="https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/logocompleto.png" alt="PieroDev logo">
    </div>

    <h2>Acceso al panel</h2>

    <?php if ($login_error !== ""): ?>
      <div class="alert-error"><?= h($login_error) ?></div>
    <?php endif; ?>

    <div class="control">
      <label for="usuario">Usuario</label>
      <input type="text" name="usuario" id="usuario" autocomplete="username" required>
    </div>

    <div class="control">
      <label for="password">Contraseña</label>
      <input type="password" name="password" id="password" autocomplete="current-password" required>
    </div>

    <input type="hidden" name="action" value="login">
    <input class="btn" type="submit" value="Entrar">
  </form>
</div>
