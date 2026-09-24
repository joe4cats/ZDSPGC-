<?php
/**
 * layout/footer.php — closes the shell and loads the shared JavaScript.
 * Pages may set $EXTRA_JS to an array of file names inside assets/js/.
 */

declare(strict_types=1);

$EXTRA_JS = $EXTRA_JS ?? [];
?>
  </div>
</main>

<?php if (empty($PAGE_BARE)): ?>
  <footer class="site">
    <div class="foot-wrap">
      <div>
        <strong><?= Helpers::e(APP_NAME) ?></strong> v<?= Helpers::e(APP_VERSION) ?><br>
        <span class="muted"><?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      </div>
      <div class="muted small">
        Attendance data is stored on the school server. Names and student numbers are
        only shown to signed-in staff — never on public pages.<br>
        QR codes are HMAC-signed; re-issuing an ID instantly voids the old printout.
      </div>
    </div>
  </footer>
  </div><!-- /.main-col -->
</div><!-- /.app-shell -->
<?php endif; ?>

<script src="<?= Helpers::e(Helpers::url('assets/js/vendor/qrcode-generator.js')) ?>"></script>
<script src="<?= Helpers::e(Helpers::url('assets/js/qr.js')) ?>"></script>
<script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>"></script>
<?php foreach ($EXTRA_JS as $js): ?>
<script src="<?= Helpers::e(Helpers::url('assets/js/' . $js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
