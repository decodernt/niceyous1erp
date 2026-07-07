{% extends 'base.tpl' %}

{% block toolbar %}
	{% include 'Snippets/v8.pagetoolbar.tpl' with { 'PageTitle': Module.ModuleName ~ ' - ' ~ lang('NICEYOUS1ERP_OrderDocuments') } %}
{% endblock %}

{% block content %}
	{% include 'header.tpl' %}

  <div class="card ">
    <div class="card-header">
      <div class="card-title">
        <h2>{% lang 'NICEYOUS1ERP_OrderDocuments' %}</h2>
      </div>
      <div class="card-toolbar">
        <form action="index.php" method="get" class="d-flex align-items-center gap-2">
          <input type="hidden" name="ToDo" value="runAddon">
          <input type="hidden" name="addon" value="{{ Module.ModuleId }}">
          <input type="hidden" name="route" value="viewOrderDocuments">
          <input type="text" name="orderId" class="form-control form-control-sm w-300px" placeholder="Order ID" value="{{ orderId }}">
          <button type="submit" class="btn btn-sm btn-primary">{% lang 'Search' %}</button>
        </form>
      </div>
    </div>
    <div class="card-body">
      {% if orderDocuments is not empty %}
        <table class="table table-striped">
          <thead>
            <tr>
              <th>Document ID</th>
              <th>Order ID</th>
              <th>Document Type</th>
              <th>Document Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {% for document in orderDocuments %}
              <tr>
                <td>{{ document.documentId }}</td>
                <td>{{ document.orderId }}</td>
                <td>{{ document.documentType }}</td>
                <td>{{ document.documentDate }}</td>
                <td>
                  <a href="index.php?ToDo=runAddon&addon={{ Module.ModuleId }}&route=deleteOrderDocument&orderId={{ document.orderId }}&documentId={{ document.documentId }}" onclick="return confirm('{% lang 'NICEYOUS1ERP_AreYouSureYouWantToDeleteThisDocument' %}'); return false;">{% lang 'Delete' %}</a>
                </td>
              </tr>
            {% endfor %}
          </tbody>
        </table>
      {% endif %}
    </div>
  </div>
{% endblock %}
