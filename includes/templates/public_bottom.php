<?php
/**
 * The rest of a public page after its controls: the format chips, the records,
 * the footer and the drawer. The scripts are in the head, deferred.
 *
 * @var array $page loading (text), tip (bool)
 */
?>
<div class="filters">
  <?php /* An unseen chip and line hold the room the real ones take when the records arrive, so nothing below jumps. */ ?>
  <div class="chips" id="formats" role="group" aria-label="Filter by format"><button type="button" class="chip-hold" tabindex="-1" aria-hidden="true">All <i>0</i></button></div>
  <span class="meta" id="countMeta">&nbsp;</span>
</div>

<main>
  <div id="content">
    <div class="state loading"><p><?= e($page['loading'] ?? 'Loading…') ?></p></div>
  </div>
</main>

<footer>
  <span>&copy; 2026 | Data from Discogs API | <a href="https://brunovida.si/contact" target="_blank" rel="noopener">Contact Bruno</a></span>
  <span>Made with &#10084;&#65039; by <a href="https://brunovida.si" target="_blank" rel="noopener">brunovida.si</a></span>
</footer>

<?php if ($page['tip'] ?? true): ?>
<div id="tip"></div>
<?php endif; ?>
<div class="overlay" id="overlay"></div>
<aside class="drawer" id="drawer">
  <button class="drawer-close" id="drawerClose" aria-label="Close">✕</button>
  <div class="drawer-cover" id="drawerCover"></div>
  <div class="drawer-body" id="drawerBody"></div>
</aside>

</body>
</html>
