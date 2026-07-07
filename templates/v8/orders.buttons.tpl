<div class="me-2">
  <button class="btn btn-sm btn-bg-light btn-active-color-primary" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-start">
    NICEYOU S1 <i class="bi bi-three-dots fs-3"></i>
  </button>
  <!--begin::Menu-->
  <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg-light-primary fw-bold w-200px py-3" data-kt-menu="true">
    <!--begin::Heading-->
    <div class="menu-item px-3">
      <div class="menu-content text-muted pb-2 px-3 fs-7 text-uppercase">{% lang 'NICEYOUS1ERP_SyncActions' %}</div>
    </div>
    <!--end::Heading-->
    <!--begin::Menu item-->
    <div class="menu-item px-3"><a href="javascript:;" id="manual_sync_niceyous1erp_%%OrderId%%" class="menu-link px-3">{% lang 'NICEYOUS1ERP_ManualSync' %}</a></div>
    <div class="menu-item px-3"><a href="index.php?ToDo=runAddon&amp;addon=addon_niceyous1erp&amp;route=viewOrderReports&amp;orderId=%%OrderId%%" class="menu-link px-3">{% lang 'NICEYOUS1ERP_ViewSyncReport' %}</a></div>
    <!--end::Menu item-->
  </div>
  <!--end::Menu-->
</div>

<script>

  KTMenu.createInstances();

  var target = document.body;
  var blockUI = KTBlockUI.getInstance(target);

  if(!blockUI){
    var blockUI = new KTBlockUI(target);
  }

  $('#manual_sync_niceyous1erp_%%OrderId%%', document).on('click', function(){

    var responseIcon = 'error';

    blockUI.block();

    $.ajax({
      url: 'index.php?ToDo=runAddon&addon=addon_niceyous1erp&func=ManualOrderSync&ordertoken=%%OrderToken%%',
      cache: false,
      dataType: 'json',
      success: function (e){

        blockUI.release();

        if(e.result == true){
          responseIcon = 'success';
        }

        Swal.fire({
          text: e.message,
          icon: responseIcon,
          buttonsStyling: false,
          confirmButtonText: lang.OK,
          customClass: {
            confirmButton: "btn fw-bold btn-primary",
          }
        })

      }
    })
  });

</script>
