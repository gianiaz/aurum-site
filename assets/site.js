const toggle = document.querySelector("[data-nav-toggle]");
const nav = document.querySelector("[data-nav]");

if (toggle && nav) {
    toggle.addEventListener("click", () => {
        const isOpen = nav.classList.toggle("is-open");
        toggle.setAttribute("aria-expanded", String(isOpen));
        toggle.querySelector(".material-symbols-outlined").textContent = isOpen ? "close" : "menu";
    });
}

document.querySelectorAll('a[href^="#"]').forEach((link) => {
    link.addEventListener("click", () => {
        nav?.classList.remove("is-open");
        toggle?.setAttribute("aria-expanded", "false");
    });
});

const newsletter = document.querySelector("[data-newsletter]");

if (newsletter) {
    newsletter.addEventListener("submit", async (event) => {
        event.preventDefault();
        const message = newsletter.querySelector("[data-form-message]");
        const button = newsletter.querySelector("button[type='submit']");

        if (message) {
            message.textContent = "";
        }

        if (button) {
            button.disabled = true;
        }

        try {
            const response = await window.fetch("/newsletter/subscribe", {
                method: "POST",
                body: new FormData(newsletter),
                headers: {
                    "Accept": "application/json",
                },
            });
            const result = await response.json();

            if (!response.ok || !result.ok) {
                throw new Error(result.message || "Subscription failed.");
            }

            newsletter.reset();
            if (message) {
                message.textContent = result.message;
            }
        } catch (error) {
            if (message) {
                message.textContent = error.message || "Subscription failed.";
            }
        }

        if (button) {
            button.disabled = false;
        }
    });
}

const languageSelect = document.querySelector("[data-language-select]");

if (languageSelect) {
    languageSelect.addEventListener("change", () => {
        window.location.href = languageSelect.value;
    });
}

document.querySelectorAll("[data-slideshow]").forEach((slideshow) => {
    const slides = Array.from(slideshow.querySelectorAll(".product-stage__slide"));
    const dots = Array.from(slideshow.querySelectorAll(".slide-dots span"));

    if (slides.length < 2) {
        return;
    }

    let current = 0;

    const showSlide = (next) => {
        slides[current].classList.remove("is-active");
        dots[current]?.classList.remove("is-active");
        current = next;
        slides[current].classList.add("is-active");
        dots[current]?.classList.add("is-active");
    };

    window.setInterval(() => {
        showSlide((current + 1) % slides.length);
    }, 4200);
});
