<?php

return [
    'services' => '服务',
    'product' => '产品',
    'price' => '价格',
    'status' => '状态',
    'name' => '名称',
    'actions' => '操作',
    'view' => '查看',

    'product_details' => '产品详情',
    'billing_cycle' => '计费周期',
    'cancel' => '取消',
    'cancellation' => ':service 的取消',
    'cancel_are_you_sure' => '您确定要取消此服务吗？',
    'cancel_reason' => '取消原因',
    'cancel_type' => '取消类型',
    'cancel_immediate' => '立即取消',
    'cancel_end_of_period' => '在计费周期结束时取消',
    'cancel_immediate_warning' => '当您按下下方的按钮后，服务将立即取消，您将无法再使用。',
    'cancellation_requested' => '已请求取消',

    'current_plan' => '当前方案',
    'new_plan' => '新方案',
    'change_plan' => '变更方案',
    'current_price' => '当前价格',
    'new_price' => '新价格',
    'upgrade' => '升级',
    'upgrade_summary' => '升级摘要',
    'total_today' => '今日总计',
    'upgrade_service' => '升级服务',
    'upgrade_choose_product' => '选取要升级的产品',
    'upgrade_choose_config' => '选择要升级的配置',
    'next_step' => '下一步',

    'upgrade_pending' => '如果已经有升级/降级的账单则无法执行',

    'outstanding_invoice' => '您有一项未支付的款项。',
    'view_and_pay' => '点击查看及支付',

    'statuses' => [
        'pending' => '等待中',
        'active' => '已启用',
        'cancelled' => '已取消',
        'suspended' => '已暂停',
        'cancellation_pending' => '取消处理中',
    ],
    'billing_cycles' => [
        'day' => '天',
        'week' => '周',
        'month' => '月',
        'year' => '年',
    ],
    'every_period' => '每 :period :unit',
    'price_every_period' => '每 :period :unit :price',
    'price_one_time' => '单次费用 :price',
    'renews_in' => '续订剩余时间：',
    'renews_on' => '续订于',
    'auto_pay' => '自动支付方式：',
    'auto_pay_not_configured' => '未设置',

    'no_services' => '未找到服务',
    'update_billing_agreement' => '更新付款协议',
    'clear_billing_agreement' => '清除付款协议',
    'select_billing_agreement' => '请选择付款协议',

    'remove_payment_method' => '移除付款方式',
    'remove_payment_method_confirm' => '确定要从此服务移除「:name」付款方式吗？移除后，系统将无法再自动支付您的账单。',

    'label' => '标签',
    'label_placeholder' => '请输入此服务的自定义标签',
    'label_modal_title' => '编辑服务标签',
    'update_label' => '更新标签',

];
