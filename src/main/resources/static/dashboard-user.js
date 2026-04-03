const email = localStorage.getItem("userEmail");
const isAdmin = localStorage.getItem("isAdmin") === "true";

if (!email) {
    window.location.href = "/login.html";
}

if (isAdmin) {
    window.location.href = "/dashboard-admin.html";
}

const whoAmI = document.getElementById("whoAmI");
const subscriptionsList = document.getElementById("subscriptionsList");

whoAmI.textContent = `Zalogowany: ${email} (USER)`;

async function loadSubscriptions() {
    const response = await fetch("/api/subscriptions");
    const items = await response.json();
    subscriptionsList.innerHTML = "";
    items.forEach(item => {
        const li = document.createElement("li");
        li.textContent = `${item.name} - ${item.monthlyCost.toFixed(2)} PLN/mies.`;
        subscriptionsList.appendChild(li);
    });
}

document.getElementById("paypalBtn").addEventListener("click", () => {
    alert("Tu podepniemy integracje PayPal w kolejnym kroku.");
});

loadSubscriptions();
