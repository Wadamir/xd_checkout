function moduleLoad(element, spinner) {
	// console.log('moduleLoad', element, spinner);
	if (spinner) {
		element
			.find(".xd_checkout-content")
			.html(
				'<div class="text-center"><i class="fa fa-spinner fa-spin fa-5x"></i></div>',
			);
	} else {
		moduleLoaded(element, spinner);

		var width = element.width();
		var height = element.height();
		var margin = height / 2 - 30;

		if (height > 30) {
			html =
				'<div class="overlay" style="position:absolute;bottom:0;left:0;z-index:99999;background:none;width:' +
				width +
				"px;height:" +
				height +
				'px;text-align:center;"><i class="fa fa-spinner fa-spin fa-5x" style="margin-top:' +
				margin +
				'px;"></i></div>';

			element.append(html);

			element.css({
				opacity: "0.5",
				position: "relative",
			});
		}
	}
}

function moduleLoaded(element, spinner) {
	// console.log('moduleLoaded', element, spinner);
	if (!spinner) {
		element.find(".overlay").remove();

		element.removeAttr("style");
	}
}

function disableCheckout() {
	$("#xd_checkout-disable").css("opacity", "0.5");

	var width = $("#xd_checkout-disable").width();
	var height = $("#xd_checkout-disable").height();

	html =
		'<div class="disable-overlay" style="position:absolute;top:0;left:0;z-index:99999;background:none;width:' +
		width +
		"px;height:" +
		height +
		'px;text-align:center;"></div>';

	$("#xd_checkout-disable").css("position", "relative").append(html);
}

// --- Helpers ---
function isValidEmail(email) {
	var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	return regex.test(email);
}

function isValidPhone(phone) {
	var regex = /^[\d\+\-\s]{6,}$/; // digits, +, -, space, min 6 chars
	return regex.test(phone);
}

function slideDown(element, duration = 300) {
	element.style.removeProperty("display");
	let display = window.getComputedStyle(element).display;
	if (display === "none") display = "block";
	element.style.display = display;

	let height = element.offsetHeight;
	element.style.overflow = "hidden";
	element.style.height = 0;

	requestAnimationFrame(function () {
		element.style.transition = `height ${duration}ms ease`;
		element.style.height = height + "px";
	});

	setTimeout(function () {
		element.style.removeProperty("height");
		element.style.removeProperty("overflow");
		element.style.removeProperty("transition");
	}, duration);
}

function slideUp(element, duration = 300) {
	element.style.height = element.offsetHeight + "px";
	element.style.overflow = "hidden";
	element.style.transition = `height ${duration}ms ease`;

	requestAnimationFrame(function () {
		element.style.height = 0;
	});

	setTimeout(function () {
		element.style.display = "none";
		element.style.removeProperty("height");
		element.style.removeProperty("overflow");
		element.style.removeProperty("transition");
	}, duration);
}

function ajaxGet(url, onSuccess, onError) {
	var xhr = new XMLHttpRequest();
	xhr.open("GET", url, true);
	xhr.onreadystatechange = function () {
		if (xhr.readyState === 4) {
			if (xhr.status >= 200 && xhr.status < 300) {
				onSuccess(JSON.parse(xhr.responseText));
			} else if (onError) {
				onError(xhr);
			}
		}
	};
	xhr.send();
}

function ajaxPostForm(url, formData, onSuccess, onError) {
	var xhr = new XMLHttpRequest();
	xhr.open("POST", url, true);
	xhr.onreadystatechange = function () {
		if (xhr.readyState === 4) {
			if (xhr.status >= 200 && xhr.status < 300) {
				onSuccess(JSON.parse(xhr.responseText));
			} else if (onError) {
				onError(xhr);
			}
		}
	};
	xhr.send(formData);
}
