{% extends 'base.tpl' %}

{% block toolbar %}
	{% include 'Snippets/v8.pagetoolbar.tpl' with { 'PageTitle': Module.ModuleName ~ ' - ' ~ lang('NICEYOUS1ERP_Mapped') } %}
{% endblock %}

{% block content %}
	{% include 'header.tpl' %}

	{# -- mapped products -------------------------------------------------- #}
	<div class="card mb-5">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_MappedProducts' %} ({{ mapped.total }})</h2>
			</div>
			<div class="card-toolbar">
				<form method="get" action="index.php" class="d-flex align-items-center">
					<input type="hidden" name="ToDo" value="runAddon">
					<input type="hidden" name="addon" value="{{ Module.ModuleId }}">
					<input type="hidden" name="route" value="viewMapped">
					<input type="text" name="search" value="{{ mapped.search }}" class="form-control form-control-sm me-3" placeholder="{% lang 'NICEYOUS1ERP_SearchMappedPlaceholder' %}">
					<button type="submit" class="btn btn-sm btn-light-primary">{% lang 'NICEYOUS1ERP_Search' %}</button>
				</form>
			</div>
		</div>
		<div class="card-body">
			{% if mapped.products is not empty %}
				{% if mapped.products|length >= mapped.listLimit %}
					<div class="text-muted mb-3">{{ lang('NICEYOUS1ERP_ShowingFirst', { 'count': mapped.listLimit }) }}</div>
				{% endif %}
				<table class="table table-striped align-middle">
					<thead>
						<tr>
							<th>#</th>
							<th>{% lang 'NICEYOUS1ERP_ProductName' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Combination' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Sku' %}</th>
							<th>EAN</th>
							<th>{% lang 'NICEYOUS1ERP_ErpItemId' %}</th>
							<th>{% lang 'NICEYOUS1ERP_LastUpdate' %}</th>
							<th class="text-end">{% lang 'NICEYOUS1ERP_Actions' %}</th>
						</tr>
					</thead>
					<tbody>
						{% for p in mapped.products %}
							<tr>
								<td><a href="index.php?ToDo=editProduct&productId={{ p.productid }}" target="_blank">{{ p.productid }}</a></td>
								<td>
									{% if p.orphan %}
										<span class="badge badge-light-danger">{% lang 'NICEYOUS1ERP_OrphanMapping' %}</span>
									{% else %}
										{{ p.prodname }}
									{% endif %}
								</td>
								<td>{% if p.combinationid > 0 %}{{ p.combinationid }}{% else %}-{% endif %}</td>
								<td>{{ p.sku }}</td>
								<td>{{ p.ean }}</td>
								<td><span class="badge badge-light">{{ p.erp_mtrl }}</span></td>
								<td>{{ p.last_update }}</td>
								<td class="text-end">
									<a href="index.php?ToDo=runAddon&addon={{ Module.ModuleId }}&route=removeProductMapping&productId={{ p.productid }}&combinationId={{ p.combinationid }}"
										class="btn btn-sm btn-light-danger"
										onclick="return confirm('{% lang 'NICEYOUS1ERP_ConfirmRemoveMapping' %}');">{% lang 'NICEYOUS1ERP_RemoveMapping' %}</a>
								</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
			{% else %}
				<div class="text-muted">{% lang 'NICEYOUS1ERP_NoMappings' %}</div>
			{% endif %}
		</div>
	</div>

	{# -- mapped categories ------------------------------------------------ #}
	<div class="card">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_MappedCategories' %} ({{ mapped.categories|length }})</h2>
			</div>
		</div>
		<div class="card-body">
			{% if mapped.categories is not empty %}
				<table class="table table-striped align-middle">
					<thead>
						<tr>
							<th>{% lang 'NICEYOUS1ERP_ErpCategoryId' %}</th>
							<th>{% lang 'NICEYOUS1ERP_ErpCategoryTitle' %}</th>
							<th>{% lang 'NICEYOUS1ERP_EshopBrand' %}</th>
							<th class="text-end">{% lang 'NICEYOUS1ERP_Actions' %}</th>
						</tr>
					</thead>
					<tbody>
						{% for cat in mapped.categories %}
							<tr>
								<td>{{ cat.erp_cat_id }}</td>
								<td>{{ cat.erp_title }}</td>
								<td><a href="index.php?ToDo=editBrand&brandId={{ cat.brandid }}" target="_blank">{{ cat.brand_title }}</a></td>
								<td class="text-end">
									<a href="index.php?ToDo=runAddon&addon={{ Module.ModuleId }}&route=removeCategoryMapping&mapId={{ cat.mapid }}"
										class="btn btn-sm btn-light-danger"
										onclick="return confirm('{% lang 'NICEYOUS1ERP_ConfirmRemoveCategoryMapping' %}');">{% lang 'NICEYOUS1ERP_RemoveMapping' %}</a>
								</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
			{% else %}
				<div class="text-muted">{% lang 'NICEYOUS1ERP_NoMappings' %}</div>
			{% endif %}
		</div>
	</div>
{% endblock %}
