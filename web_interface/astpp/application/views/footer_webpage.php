</section>
</span></span>
<script type="text/javascript" src="<?php echo base_url(); ?>assets/js/jquery.dlmenu.js"<?php echo csp_nonce_attr(); ?>></script>
<script<?php echo csp_nonce_attr(); ?>>
	$(document).ready(function() {
  $('.btn-quick > div:first-child').on('click', function(){
    $('.btn-quick').toggleClass('open');
  })
      $(document).on("click", function(event){
        if(!$(event.target).is(".btn-quick, .btn-quick > div, .btn-quick > div > i, .btn-quick div label"))
            $('.btn-quick').removeClass('open');
        })
})
</script>
<script<?php echo csp_nonce_attr(); ?> type="text/javascript" language="javascript">
$('.dropdown-menu a.dropdown-toggle').on('click', function(e) {
  if (!$(this).next().hasClass('show')) {
    $(this).parents('.dropdown-menu').first().find('.show').removeClass("show");
  }
  var $subMenu = $(this).next(".dropdown-menu");
  $subMenu.toggleClass('show');

  $(this).parents('li.nav-item.dropdown.show').on('hidden.bs.dropdown', function(e) {
    $('.dropdown-submenu .show').removeClass("show");
  });

  return false;
});
</script>



<script<?php echo csp_nonce_attr(); ?> type="text/javascript" language="javascript">
$(document).ready(function() {
  var cnt = $("#ListForm").contents();
  $("#ListForm").replaceWith(cnt);
   
   $('.form-control').on('focus blur', function (e) {
        $(this).parents('.form-group').toggleClass('focused', (e.type === 'focus' || this.value.length > 0));
  }).trigger('blur');

  $('.multiselectable').on('focus blur', function (e) {
        $(this).parents('.form-group').toggleClass('focused');
  }).trigger('blur');

    $("input[type='hidden']").parents('li.form-group').addClass("d-none");
  
  $('.selectpicker').selectpicker();
  
  $("button, a, .btn").attr("data-ripple"," "); 
   $("textarea").parents('li.form-group').addClass("h-auto");
   $(".fileinput-new").parents('span.btn').css("position","relative");
      
  $(".language").on('change',function(){
	var language=$(".language").val();
	get_lang(language);
  });

  $("no-ripple").removeAttr("data-ripple"," "); 
  
});
</script>
 <script<?php echo csp_nonce_attr(); ?>>
 jQuery(document).ready(function($) {
  $('a[rel*=facebox]').facebox()
}) 
</script>

<script<?php echo csp_nonce_attr(); ?>>
function createOptions(number) {
  var options = [], _options;

  for (var i = 0; i < number; i++) {
    var option = '<option value="' + i + '">Option ' + i + '</option>';
    options.push(option);
  }

  _options = options.join('');
  
  $('.multiselectable')[0].innerHTML = _options;
}

</script>

<script<?php echo csp_nonce_attr(); ?>>

jQuery(function($) {

  $(document).on("mousedown", "[data-ripple]", function(e) {
    
    var $self = $(this);
    
    if($self.is(".btn-disabled")) {
      return;
    }
    if($self.closest("[data-ripple]")) {
      e.stopPropagation();
    }
    
    var initPos = $self.css("position"),
        offs = $self.offset(),
        x = e.pageX - offs.left,
        y = e.pageY - offs.top,
        dia = Math.min(this.offsetHeight, this.offsetWidth, 100), // start diameter
        $ripple = $('<div/>', {class : "ripple",appendTo : $self });
    
    if(!initPos || initPos==="static") {
      $self.css({position:"relative"});
    }
    
    $('<div/>', {
      class : "rippleWave",
      css : {
        background: $self.data("ripple"),
        width: dia,
        height: dia,
        left: x - (dia/2),
        top: y - (dia/2),
      },
      appendTo : $ripple,
      one : {
        animationend : function(){
          $ripple.remove();
        }
      }
    });
  });

});

</script>  

<footer class="site-footer border_box"> 
   <div class="col text-center">
   <div class="row">
  <?php
	/*
	 * ASTPP fork: footer without vendor branding (social media, "Powered by Inextrix").
	 * Left: operator - or the footer text a reseller set for its customers (white label).
	 * Middle: origin and license (GNU AGPL v3 "Appropriate Legal Notices": copyright, no
	 * warranty, where to find the license) - shown on every page, also in reseller portals.
	 */
	$astpp_footer_text = (string) $this->session->userdata('user_footer');
	$astpp_logintype = $this->session->userdata('logintype');
	$astpp_show_operator = ($astpp_footer_text === '' || $astpp_footer_text === 'Inextrix Technologies Pvt. Ltd All Rights Reserved.' || $astpp_logintype == 2 || $astpp_logintype == -1);
  ?>
   <div class="col-md-4 py-2 px-2 text-md-left text-light astpp-footer-operator">
	<?php if ($astpp_show_operator) { ?>
		&copy; <?php echo date('Y'); ?> <a href="https://infrahost.ch" target="_blank" rel="noopener">infrahost</a>
	<?php } else { ?>
		<?php echo htmlspecialchars($astpp_footer_text, ENT_QUOTES, 'UTF-8'); ?>
	<?php } ?>
   </div>
   <div class="col-md-4 py-2 px-2 text-md-center text-light astpp-footer-legal">
	<?php echo gettext('Based on'); ?> <a href="https://github.com/iNextrix/ASTPP" target="_blank" rel="noopener">ASTPP</a> &copy; iNextrix Technologies Pvt. Ltd.<br>
	<?php echo gettext('Free software under the'); ?> <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener">GNU AGPL v3</a>, <?php echo gettext('without any warranty'); ?>.
   </div>
   <div class="col-md-4 py-2 px-2 text-md-right" id="floating-label">
    <select class="col-lg-3 col-md-6 form-control selectpicker language" data-live-search='false' data-live-search-style='begins'>
      <option title="English" id="close-image" name="en_EN" value="en_EN">English</option>
      <option title="Español" id="close-image" name="es_ES" value="es_ES">Spanish</option>
      <option title="Français" id="close-image" name="fr_FR" value="fr_FR">French</option>
      <option title="Português" id="close-image" name="pt_BR" value="pt_BR">Portuguese</option>

    </select>
       
  	<div class="col-md-12 float-right pt-4 pr-0 text-light text-md-right version_size"> <?php echo gettext("Version")?> <?php echo common_model::$global_config['system_config']['version']; ?>  

  	</div>
	</div>
	
   </div>
   </div>
</footer>
<!-- PRIVACY: vendor Google Analytics (gtag G-Y72F0B6ZN1) removed - it reported every page view to the vendor -->
</body>
</body>
</html>
 

