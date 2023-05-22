@section('jqueryUI')
    <!-- 引入 jQuery -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <!-- 引入 jQuery UI -->
    <script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>

    <script>
        $(function () {
            $("#slider-vertical").slider({
                orientation: "vertical",
                range: "min",
                min: 1,
                max: 12,
                value: 1,
                slide: function (event, ui) {
                    $("#month").text(ui.value);
                }
            });
            $("#month").text($("#slider-vertical").slider("value"));
        });
    </script>
@endsection