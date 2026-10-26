</section>
</span></span>
<div class="overlay">
    <div id="loading-img"></div>
</div>
<script type="text/javascript" src="<?php echo base_url(); ?>assets/js/jquery.dlmenu.js"<?php echo csp_nonce_attr(); ?>></script>
<script<?php echo csp_nonce_attr(); ?> type="text/javascript">
    $(document).ready(function(){
        var jqueryarray = <?php echo json_encode($this->tooltip_data); ?>;
        //console.log(jqueryarray);
        if(jqueryarray && jqueryarray !=''){
          var form_name = $('form').attr("name");
          $.each(jqueryarray, function (key, val) {
            key = key.replace(form_name+'_','');
            // console.log(key);
            /*If condition used for select,input and textarea tag purpose*/
            if(key == 'customer_type' || key == 'country_id[]' || key == 'accounts_id[]' || key == 'fields_in_csv[]' || key == 'recuring_type' || key == 'product_id' || key == 'product_category' || key == 'product_rate_group' || key == 'product_rate_group[]' ||key == 'debit_amt' || key == 'email_notify' || key == 'despostion[]' || key == 'template' || key == 'call_forwarding_flag' || key == 'on_busy_flag' || key == 'no_answer_flag' || key == 'not_register_flag' ){

              $('select[name="'+key+'"] , input[name="'+key+'"] , textarea[name="'+key+'"]').parent().parent().find('label').attr({'data-toggle':'tooltip', 'data-html':"true",'data-original-title':val, 'data-placement' : 'right'}); 
            }else{

               $('select[name="'+key+'"] , input[name="'+key+'"]  , textarea[name="'+key+'"]').parent().find('label').attr({'data-toggle':'tooltip', 'data-html':"true",'data-original-title':val, 'data-placement' : 'right'});
             }
          });
        }
    });
  </script>
<script<?php echo csp_nonce_attr(); ?>>
	$(document).ready(function() {

  //ASTPPENT-1172 Start
  if($('body .sidebar').length > 0)
  {
    $('body').addClass('with-sidebar');
  }
  else
  {
    $('body').addClass('no-sidebar');
  }
  //ASTPPENT-1172 End    

  $('[data-toggle="tooltip"]').tooltip(
     { boundary: 'window' }
  );  
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
   
  $('.multiselectable').on('focus blur', function (e) {
        $(this).parents('.form-group').toggleClass('focused');
  }).trigger('blur');

    $("input[type='hidden']").parents('li.form-group').addClass("d-none");
  
  $('.selectpicker').selectpicker();
  
  $("button, a, .btn").attr("data-ripple"," "); 
   $("textarea").parents('li.form-group').addClass("h-auto");
   $(".fileinput-new").parents('span.btn').css("position","relative");
   

  
  $(".tooltips.error_div").on('click', function() {
      $("div.tooltips span").addClass("tooltips_selected");
   });
  
  $("#reseller_batch_update .col-md-4.input-group .gj-datepicker").addClass("col-md-6 p-0");
  $("#customer_batch_update .col-md-4.input-group .gj-datepicker").addClass("col-md-6 p-0");
  $("#language_drp_sown").on('change',function(){
  var language=$("#language_drp_sown").val();
  get_lang(language);
  });

  $("no-ripple").removeAttr("data-ripple"," "); 
 
 
});

</script>
 <script<?php echo csp_nonce_attr(); ?>>
 jQuery(document).ready(function($) {
  $('a[rel*=facebox]').facebox();
  <?php // ASTPPCOM-941 Start ?>
  $("#report_change").on('change',function(){
    var report_change=$("#report_change").val();
    window.location.href = report_change;
  });
  <?php //ASTPPCOM-941 END ?>
}) 
</script>
    <script<?php echo csp_nonce_attr(); ?> type="text/javascript" charset="utf-8">

    function css_browser_selector(u){var ua=u.toLowerCase(),is=function(t){return ua.indexOf(t)>-1},g='gecko',w='webkit',s='safari',o='opera',m='mobile',h=document.documentElement,b=[(!(/opera|webtv/i.test(ua))&&/msie\s(\d)/.test(ua))?('ie ie'+RegExp.$1):is('firefox/2')?g+' ff2':is('firefox/3.5')?g+' ff3 ff3_5':is('firefox/3.6')?g+' ff3 ff3_6':is('firefox/3')?g+' ff3':is('gecko/')?g:is('opera')?o+(/version\/(\d+)/.test(ua)?' '+o+RegExp.$1:(/opera(\s|\/)(\d+)/.test(ua)?' '+o+RegExp.$2:'')):is('konqueror')?'konqueror':is('blackberry')?m+' blackberry':is('android')?m+' android':is('chrome')?w+' chrome':is('iron')?w+' iron':is('applewebkit/')?w+' '+s+(/version\/(\d+)/.test(ua)?' '+s+RegExp.$1:''):is('mozilla/')?g:'',is('j2me')?m+' j2me':is('iphone')?m+' iphone':is('ipod')?m+' ipod':is('ipad')?m+' ipad':is('mac')?'mac':is('darwin')?'mac':is('webtv')?'webtv':is('win')?'win'+(is('windows nt 6.0')?' vista':''):is('freebsd')?'freebsd':(is('x11')||is('linux'))?'linux':'','js']; c = b.join(' '); h.className += ' '+c; return c;}; css_browser_selector(navigator.userAgent);
    </script>
<script<?php echo csp_nonce_attr(); ?>>
$(document).bind('reveal.facebox', function() { 
    $(".main").removeClass("d-none");
    $("body").remove('#facebox_overlay');
    $(".main").append('<div id="facebox_overlay" class="facebox_hide" />');  
   
})
$(document).bind('close.facebox', function() {
    $(".main").addClass("d-none");
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
        dia = Math.min(this.offsetHeight, this.offsetWidth, 100), 
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


  <?php 


  $language_name_query=$this->common_model->get_language_list();

?>
<footer class="site-footer border_box"> 
   <div class="col text-center">
   <div class="row">
  <?php
	/*
	 * ASTPP fork: footer without vendor branding (social media, "Powered by Inextrix").
	 * Left: operator - or the footer text a reseller set for its customers (white label).
	 * Middle: origin and license (GNU AGPL v3 "Appropriate Legal Notices": copyright, no
	 * warranty, where to find the license) - shown on every page, also in reseller portals -
	 * and the source code offer of AGPL section 13 (config item source_url).
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
	<a href="<?php echo htmlspecialchars((string) $this->config->item('source_url'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo gettext('Source code'); ?></a>
   </div>
   <div class="col-md-4 py-2 px-2 text-md-right" id="floating-label">
     <select class="col-lg-3 col-md-6 form-control selectpicker language" data-live-search='false' data-live-search-style='begins' id="language_drp_sown">
  <?php

  
foreach ($language_name_query as $key => $value) {
$selected_locale = $this->session->userdata ( 'user_language');
if (isset($selected_locale) && $value['locale'] == $selected_locale) {
  $selected = 'selected="selected"';
}else {
  $selected='';
}
?>
<option title="<?php echo $value['name']; ?>" id="close-image" name="<?php echo $value['locale']; ?>" value="<?php echo $value['locale']; ?>" <?= $selected ?>><?php echo $value['name']; ?></option>
<?
} ?> 
        </select>



<div class="col-md-12 float-right pt-4 pr-0 text-light text-md-right version_size"><?php echo gettext("Version")?> <?php echo common_model::$global_config["system_config"]["version"]; ?>
<?php

?>

  	</div>
	</div>
	
   </div>
   </div>
</footer>
<!-- PRIVACY: vendor Google Analytics (gtag G-Y72F0B6ZN1) removed - it reported every page view to the vendor -->
</body>
</body>
</html>
 

