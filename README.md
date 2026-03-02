Container Import ERP

Container Import ERP is a lightweight ERP-style cost allocation system built for WordPress. It enables import businesses to accurately calculate landed costs per product by distributing total container charges across all items proportionally.

🎯 Purpose

Importers often receive:

CCC (Container China Cost) – supplier invoice value

CSLC (Container Shipping & Landing Cost) – freight, customs, duties, inland logistics

This plugin distributes those total container costs across products based on their invoice value share and calculates:

Allocated CCC per product

Allocated CSLC per product

Total landed cost

Landed cost per unit

⚙️ Core Features

Automatic proportional cost allocation

Per-unit landed cost calculation

Secure admin interface

Nonce-protected form handling

Singleton OOP architecture

Printable report support

🧮 Calculation Logic

For each product:

Invoice Ratio = Product Invoice Value ÷ Total Invoice Value

Allocated CCC = Invoice Ratio × Total CCC
Allocated CSLC = Invoice Ratio × Total CSLC

Landed Cost Per Unit = (Allocated CCC + Allocated CSLC) ÷ Quantity

👨‍💼 Use Case

Ideal for:

Import/export businesses

Container-based procurement

Wholesale distributors

ERP cost tracking systems

🏗 Architecture

Object-Oriented

Singleton pattern

Modular structure

WordPress admin integration

Secure database handling
