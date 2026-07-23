{% extends 'base.tpl' %}

{% block toolbar %}
	{% include 'Snippets/v8.pagetoolbar.tpl' with { 'PageTitle': Module.ModuleName ~ ' - ' ~ lang('NICEYOUS1ERP_Mappings') } %}
{% endblock %}

{% block content %}
	{% include 'header.tpl' %}

	{# -- stat cards ------------------------------------------------------- #}
	<div class="row g-5 mb-5">
		{% for stat in [
			{ 'title': 'NICEYOUS1ERP_SimpleProducts', 'data': mappings.simple },
			{ 'title': 'NICEYOUS1ERP_Variations', 'data': mappings.combinations },
			{ 'title': 'NICEYOUS1ERP_ErpCategories', 'data': mappings.categories }
		] %}
			<div class="col-md-4">
				<div class="card h-100">
					<div class="card-body">
						<div class="fw-bold text-gray-600 mb-3">{{ lang(stat.title) }}</div>
						<div class="d-flex align-items-baseline mb-3">
							<span class="fs-2hx fw-bold me-2">{{ stat.data.mapped }}</span>
							<span class="fs-5 text-gray-500">/ {{ stat.data.total }}</span>
							<span class="ms-auto badge badge-light fs-7">{{ stat.data.percent }}%</span>
						</div>
						<div class="progress h-8px w-100 mb-3">
							<div class="progress-bar {{ stat.data.barClass }}" role="progressbar" style="width: {{ stat.data.percent }}%"></div>
						</div>
						<div class="d-flex justify-content-between fs-7">
							<span class="text-success">{% lang 'NICEYOUS1ERP_Mapped' %}: {{ stat.data.mapped }}</span>
							<span class="{% if stat.data.unmapped > 0 %}text-danger{% else %}text-gray-500{% endif %}">{% lang 'NICEYOUS1ERP_Unmapped' %}: {{ stat.data.unmapped }}</span>
						</div>
					</div>
				</div>
			</div>
		{% endfor %}
	</div>

	{% if mappings.noEanCount > 0 %}
		<div class="alert alert-info d-flex align-items-center mb-5">
			<div>
				<span class="fw-bold">{{ mappings.noEanCount }}</span>
				{% lang 'NICEYOUS1ERP_ProductsWithoutEanNote' %}
			</div>
		</div>
	{% endif %}

	{# -- unmapped products ------------------------------------------------ #}
	<div class="card mb-5">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_UnmappedProducts' %} ({{ mappings.unmappedProductsTotal }})</h2>
			</div>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-danger" disabled
					data-mappings-delete-selected
					data-confirm="{% lang 'NICEYOUS1ERP_ConfirmDeleteProducts' %}">{% lang 'NICEYOUS1ERP_DeleteSelected' %}</button>
			</div>
		</div>
		<div class="card-body">
			{% if mappings.unmappedProductsTotal > 0 %}
				<table class="table table-striped align-middle">
					<thead>
						<tr>
							<th class="w-25px"><input type="checkbox" class="form-check-input" data-mappings-select-all></th>
							<th>#</th>
							<th>{% lang 'NICEYOUS1ERP_ProductName' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Sku' %}</th>
							<th>EAN</th>
						</tr>
					</thead>
					<tbody data-mappings-body="unmappedProducts">
						{% for p in mappings.unmappedProducts %}
							<tr>
								<td><input type="checkbox" class="form-check-input" data-mappings-select value="{{ p.productid }}"></td>
								<td><a href="index.php?ToDo=editProduct&productId={{ p.productid }}" target="_blank">{{ p.productid }}</a></td>
								<td>{{ p.prodname }}</td>
								<td>{{ p.prodcode }}</td>
								<td>{{ p.european_article_number }}</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
				<div class="d-flex justify-content-end mt-3" data-mappings-pager data-mappings-table="unmappedProducts" data-total="{{ mappings.unmappedProductsTotal }}" data-page-size="{{ mappings.pageSize }}"></div>
			{% else %}
				<div class="text-success">{% lang 'NICEYOUS1ERP_AllMapped' %}</div>
			{% endif %}
		</div>
	</div>

	{# -- unmapped variations ---------------------------------------------- #}
	<div class="card mb-5">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_UnmappedVariations' %} ({{ mappings.unmappedCombinationsTotal }})</h2>
			</div>
		</div>
		<div class="card-body">
			{% if mappings.unmappedCombinationsTotal > 0 %}
				<table class="table table-striped">
					<thead>
						<tr>
							<th>#</th>
							<th>{% lang 'NICEYOUS1ERP_ProductName' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Combination' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Sku' %}</th>
							<th>Barcode</th>
						</tr>
					</thead>
					<tbody data-mappings-body="unmappedCombinations">
						{% for c in mappings.unmappedCombinations %}
							<tr>
								<td><a href="index.php?ToDo=editProduct&productId={{ c.vcproductid }}" target="_blank">{{ c.vcproductid }}</a></td>
								<td>{{ c.prodname }}</td>
								<td>{{ c.combinationid }}</td>
								<td>{{ c.vcsku }}</td>
								<td>{{ c.vcbarcode }}</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
				<div class="d-flex justify-content-end mt-3" data-mappings-pager data-mappings-table="unmappedCombinations" data-total="{{ mappings.unmappedCombinationsTotal }}" data-page-size="{{ mappings.pageSize }}"></div>
			{% else %}
				<div class="text-success">{% lang 'NICEYOUS1ERP_AllMapped' %}</div>
			{% endif %}
		</div>
	</div>

	{# -- products without EAN --------------------------------------------- #}
	{% if mappings.noEanCount > 0 %}
		<div class="card mb-5">
			<div class="card-header">
				<div class="card-title">
					<h2>{% lang 'NICEYOUS1ERP_NoEanProducts' %} ({{ mappings.noEanCount }})</h2>
				</div>
			</div>
			<div class="card-body">
				<table class="table table-striped">
					<thead>
						<tr>
							<th>#</th>
							<th>{% lang 'NICEYOUS1ERP_ProductName' %}</th>
							<th>{% lang 'NICEYOUS1ERP_Sku' %}</th>
						</tr>
					</thead>
					<tbody data-mappings-body="noEan">
						{% for p in mappings.noEanProducts %}
							<tr>
								<td><a href="index.php?ToDo=editProduct&productId={{ p.productid }}" target="_blank">{{ p.productid }}</a></td>
								<td>{{ p.prodname }}</td>
								<td>{{ p.prodcode }}</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
				<div class="d-flex justify-content-end mt-3" data-mappings-pager data-mappings-table="noEan" data-total="{{ mappings.noEanCount }}" data-page-size="{{ mappings.pageSize }}"></div>
			</div>
		</div>
	{% endif %}

	{# -- unmatched ERP categories (brands) -------------------------------- #}
	<div class="card">
		<div class="card-header">
			<div class="card-title">
				<h2>{% lang 'NICEYOUS1ERP_UnmatchedCategories' %} ({{ mappings.unmatchedCategoriesTotal }})</h2>
			</div>
		</div>
		<div class="card-body">
			{% if mappings.unmatchedCategoriesTotal > 0 %}
				<div class="text-muted mb-3">{% lang 'NICEYOUS1ERP_UnmatchedCategoriesNote' %}</div>
				<table class="table table-striped">
					<thead>
						<tr>
							<th>{% lang 'NICEYOUS1ERP_ErpCategoryId' %}</th>
							<th>{% lang 'NICEYOUS1ERP_ErpCategoryTitle' %}</th>
						</tr>
					</thead>
					<tbody data-mappings-body="unmatchedCategories">
						{% for cat in mappings.unmatchedCategories %}
							<tr>
								<td>{{ cat.erp_cat_id }}</td>
								<td>{{ cat.erp_title }}</td>
							</tr>
						{% endfor %}
					</tbody>
				</table>
				<div class="d-flex justify-content-end mt-3" data-mappings-pager data-mappings-table="unmatchedCategories" data-total="{{ mappings.unmatchedCategoriesTotal }}" data-page-size="{{ mappings.pageSize }}"></div>
			{% else %}
				<div class="text-success">{% lang 'NICEYOUS1ERP_AllMapped' %}</div>
			{% endif %}
		</div>
	</div>

	<script src="/addons/niceyous1erp/templates/v8/assets/js/mappings.pagination.js?{{ JSCacheToken }}"></script>
{% endblock %}
