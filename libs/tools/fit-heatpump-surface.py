#!/usr/bin/env python3
"""Reproduce the reviewed intake-aware surface and grouped validation offline.

Requires NumPy/SciPy. Reads recorded observations without editing them. No live
connections, counter corrections, invented measurements or actuator commands.
"""
import argparse
import hashlib
import itertools
import json
from pathlib import Path

import numpy as np
from scipy.optimize import LinearConstraint, minimize
from scipy.spatial import ConvexHull, Delaunay, distance

FEATURES = [(0, 0, 0), (1, 0, 0), (0, 1, 0), (0, 2, 0),
            (0, 3, 0), (0, 0, 1), (1, 1, 0), (0, 1, 1)]
ALPHAS = [0.01, 0.1, 0.3, 1.0, 3.0, 10.0]
DOMAIN = dict(outside_min_C=3.25, outside_max_C=16.0,
              power_min_percent=30, power_max_percent=100,
              incoming_min_C=9.5, incoming_max_C=25.25)
NORMALIZATION = dict(outside_center_C=10.5, outside_scale_C=5.5,
                     power_center_percent=65, power_scale_percent=35,
                     incoming_center_C=17.5, incoming_scale_C=8.0)


def dump(path, obj):
    path.write_text(json.dumps(obj, indent=2, ensure_ascii=False, allow_nan=False) + '\n')


def normalized(t, p, i):
    return np.column_stack(((np.asarray(t)-10.5)/5.5,
                            (np.asarray(p)-65)/35, (np.asarray(i)-17.5)/8))


def design(x):
    return np.column_stack([np.prod(x**f, axis=1) for f in FEATURES])


def derivative(x, axis):
    columns = []
    for f in FEATURES:
        powers = list(f)
        powers[axis] = max(0, powers[axis]-1)
        columns.append(f[axis]*np.prod(x**powers, axis=1))
    return np.column_stack(columns)


def minimum_power_derivative(b):
    # The derivative is quadratic in power and affine in both temperatures.
    powers = [-1., 1.]
    if b[4] > 0:
        vertex = -b[3]/(3*b[4])
        if -1 < vertex < 1:
            powers.append(vertex)
    queries = np.array(list(itertools.product(
        [(DOMAIN['outside_min_C']-10.5)/5.5, 1.], powers,
        [(DOMAIN['incoming_min_C']-17.5)/8, (DOMAIN['incoming_max_C']-17.5)/8])))
    values = derivative(queries, 1) @ b
    return float(values.min()), queries[int(values.argmin())]


def fit(x, y, alpha):
    a = design(x)
    reg = np.eye(len(FEATURES))*alpha
    reg[0, 0] = 0  # Do not shrink the intercept.
    h, g = a.T@a+reg, a.T@np.log(y)
    initial = np.linalg.solve(h, g).T.ravel()
    q = np.array(list(itertools.product(
        [(DOMAIN['outside_min_C']-10.5)/5.5, 1.], [-1., 0., 1.],
        [(DOMAIN['incoming_min_C']-17.5)/8, (DOMAIN['incoming_max_C']-17.5)/8])))
    dt, di, dp = (derivative(q, k) for k in (0, 2, 1))
    zero = np.zeros_like(dp)
    constraints = np.unique(np.r_[np.c_[dt, zero], np.c_[-di, zero],
                                  np.c_[zero, dp], np.c_[dp, dp]], axis=0)

    def objective(v):
        b = v.reshape(2, -1).T
        return float(np.sum(b*(h@b))-2*np.sum(b*g))

    def jac(v):
        return (2*(h@v.reshape(2, -1).T-g)).T.ravel()

    for _ in range(12):
        result = minimize(objective, initial, jac=jac,
                          constraints=LinearConstraint(constraints, 0, np.inf),
                          method='SLSQP', options={'ftol': 1e-11, 'maxiter': 500})
        if not result.success:
            raise RuntimeError('Constrained fit failed: '+result.message)
        b = result.x.reshape(2, -1).T
        additions = []
        for coefs, mode in [(b[:, 1], 'power'), (b.sum(axis=1), 'heat')]:
            minimum, query = minimum_power_derivative(coefs)
            if minimum < -1e-9:
                d = derivative(query[None, :], 1)[0]
                additions.append(np.r_[np.zeros(len(d)) if mode == 'power' else d, d])
        if not additions:
            if (constraints@result.x).min() < -1e-8:
                raise RuntimeError('Physical constraint violation')
            return b
        constraints = np.r_[constraints, additions]
        initial = result.x
    raise RuntimeError('Power-derivative constraints did not converge')


def predict(x, coefficients):
    return np.exp(design(x)@coefficients)


def metrics(pred, actual):
    error = pred-actual
    rise_error = (np.prod(pred, axis=1)-np.prod(actual, axis=1))/.67
    return dict(rmse_COP=float(np.sqrt(np.mean(error[:, 0]**2))),
                rmse_electrical_kW=float(np.sqrt(np.mean(error[:, 1]**2))),
                rmse_air_rise_C=float(np.sqrt(np.mean(rise_error**2))),
                mean_error_COP=float(error[:, 0].mean()),
                maximum_absolute_error_COP=float(np.abs(error[:, 0]).max()))


def select_alpha(x, y, runs):
    scores = []
    for alpha in ALPHAS:
        predicted = np.empty_like(y)
        for run in sorted(set(runs)):
            train = runs != run
            predicted[~train] = predict(x[~train], fit(x[train], y[train], alpha))
        scores.append(dict(alpha=alpha, **metrics(predicted, y)))
    return min(scores, key=lambda r: (r['rmse_COP'], r['alpha']))['alpha'], scores


def legacy_predictions(x, y, runs):
    # Exactly the prior v5 zero-smoothing TPS fit to heat and electrical input.
    from scipy.interpolate import RBFInterpolator
    predicted = np.empty_like(y)
    thermal = np.column_stack((np.prod(y, axis=1), y[:, 1]))
    for run in sorted(set(runs)):
        train = runs != run
        result = RBFInterpolator(x[train, :2], thermal[train], smoothing=0,
                                 kernel='thin_plate_spline', degree=1)(x[~train, :2])
        predicted[~train] = np.column_stack((result[:, 0]/result[:, 1], result[:, 1]))
    return predicted


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    data = Path(__file__).resolve().parents[2]/'docs/data'
    out = args.output or data
    out.mkdir(parents=True, exist_ok=True)
    raw = (data/'heatpump-cop-surface-observations.json').read_bytes()
    source = json.loads(raw)
    baseline = json.loads((data/'heatpump-cop-surface-v5.json').read_text())
    assert hashlib.sha256(raw).hexdigest() == baseline['source_sha256']
    points = [p for p in source['points'] if p['stable'] and p['usable'] and not p.get('supersededBy')]
    x = normalized([p['outside'] for p in points], [p['powerPercent'] for p in points],
                   [p['incoming'] for p in points])
    y = np.array([[p['estimatedSystemCOP'], p['systemInputKW']] for p in points])
    runs = np.array([p['runID'] for p in points])
    assert len(points) == 47 and len(set(runs)) == 6
    alpha, tuning = select_alpha(x, y, runs)
    coefficients = fit(x, y, alpha)
    nested = np.empty_like(y)
    folds = []
    for run in sorted(set(runs)):
        train = runs != run
        inner_alpha, _ = select_alpha(x[train], y[train], runs[train])
        nested[~train] = predict(x[~train], fit(x[train], y[train], inner_alpha))
        folds.append(dict(heldOutRun=run, points=int((~train).sum()),
                          selectedAlpha=inner_alpha, **metrics(nested[~train], y[~train])))
    prior = legacy_predictions(x, y, runs)
    fit_values = predict(x, coefficients)
    validation = dict(schema=1, modelVersion='provisional-heatpump-surface-2026-11-12-v6',
                      source_sha256=hashlib.sha256(raw).hexdigest(),
                      protocol='Outer leave-one-complete-run-out; alpha selected only from the other five runs using inner whole-run holdouts. Internal validation of six existing runs, not an independent field trial.',
                      selectedAlpha=alpha, alphaCandidates=ALPHAS, tuning=tuning,
                      previous_v5=metrics(prior, y), nested=metrics(nested, y),
                      in_sample=metrics(fit_values, y), folds=folds,
                      predictions=[dict(pointID=p['pointID'], runID=p['runID'],
                                        observedCOP=float(y[n, 0]), heldOutCOP=float(nested[n, 0]),
                                        observedElectricalKW=float(y[n, 1]), heldOutElectricalKW=float(nested[n, 1]),
                                        fittedCOP=float(fit_values[n, 0])) for n, p in enumerate(points)])
    planes = ConvexHull(x).equations
    # Common 3D support describes all three independent inputs, not only weather/power.
    temperatures = np.arange(3.25, 16.001, .25)
    powers = np.arange(30, 101)
    inlets = [10., 15., 20., 25.]
    t, p, i = np.meshgrid(temperatures, powers, inlets, indexing='ij')
    query = normalized(t.ravel(), p.ravel(), i.ravel())
    values = predict(query, coefficients).reshape(t.shape+(2,))
    support = np.all(query@planes[:, :3].T+planes[:, 3] <= 1e-9, axis=1).reshape(t.shape)
    cop, electric = values[:, :, :, 0], values[:, :, :, 1]
    heat = cop*electric
    assert cop.min() > .5 and cop.max() < 10 and electric.min() > .05
    assert np.diff(cop, axis=0).min() > -1e-9  # no artificial cold-weather dip
    assert np.diff(cop, axis=2).max() < 1e-9
    assert np.diff(electric, axis=1).min() > -1e-9 and np.diff(heat, axis=1).min() > -1e-9
    for name, b in [('input', coefficients[:, 1]), ('heat', coefficients.sum(axis=1))]:
        validation[name+'_minimum_normalized_power_derivative'] = minimum_power_derivative(b)[0]
    model = dict(schema=3, version=validation['modelVersion'],
                 status='Reviewed smooth intake-aware model; provisional absolute COP; measured-point mode remains the default.',
                 method='Constrained ridge regression of log(COP) and log(electrical kW); cubic command response with outdoor/intake interactions.',
                 formula='t=(Tout-10.5)/5.5; p=(command-65)/35; i=(Tin-17.5)/8; basis=[1,t,p,p²,p³,i,t*p,p*i]. [COP,kW]=exp(basis*coefficients). Heat=COP*kW; rise=heat/(0.000335*2000).',
                 normalization=NORMALIZATION, domain=DOMAIN, feature_exponents=FEATURES,
                 coefficients=coefficients.tolist(), ridge_alpha=alpha,
                 source_sha256=hashlib.sha256(raw).hexdigest(),
                 support_hull_equations_normalized=planes.tolist(),
                 reference_basis={**baseline['reference_basis'], 'chart_incoming_C': 15.,
                                  'incoming_air_range_C': [min(p['incoming'] for p in points), max(p['incoming'] for p in points)]},
                 physical_constraints=['COP does not decrease with outdoor temperature at fixed command and intake.',
                                       'COP does not increase with intake temperature at fixed command and outdoor temperature.',
                                       'Electrical input and delivered heat do not decrease with command at fixed temperatures.',
                                       'Positive COP/input by logarithmic response; no artificial ceiling at manufacturer reference power.'],
                 metrics={**baseline['metrics'],
                          'leave_one_run_out_rmse_COP': validation['nested']['rmse_COP'],
                          'leave_one_run_out_rmse_air_rise_C': validation['nested']['rmse_air_rise_C'],
                          'leave_one_run_out_rmse_electrical_kW': validation['nested']['rmse_electrical_kW'],
                          'previous_v5_rmse_COP': validation['previous_v5']['rmse_COP'],
                          'leave_one_run_out': folds,
                          'validation_protocol': validation['protocol'],
                          'grid_cells': int(t.size), 'inside_hull_cells': int(support.sum()),
                          'extrapolation_cells': int((~support).sum())},
                 update_policy='Raw 51-row history and 47 eligible fit observations are unchanged. New tests require a reviewed offline refit, including repeated grouped validation.',
                 limitations=['Estimated 2000 m³/h airflow is not independently measured; absolute COP remains provisional.',
                              'Historical electrical scopes differ and are confounded with weather/intake; no numerical meter correction is justified.',
                              'Only six eligible COP runs; sparse three-input support. Faded chart cells extrapolate beyond the observed 3D hull.',
                              'Reviewed retained heating intervals include some original stability warnings. This is not a certified steady-state, seasonal or complete-cycle model.',
                              'Recorded defrost-like/recovery exclusions remain unchanged. No generic defrost, cycling or 5.95kW clipping correction is invented.'])
    dump(out/'heatpump-cop-surface.json', model)
    dump(out/'heatpump-surface-validation.json', validation)
    slices = []
    for n, inlet in enumerate(inlets):
        slices.append(dict(incoming_C=inlet, estimated_COP=cop[:, :, n].tolist(),
                           estimated_air_rise_C=(heat[:, :, n]/.67).tolist(),
                           estimated_heat_kW=heat[:, :, n].tolist(),
                           estimated_electrical_input_kW=electric[:, :, n].tolist(),
                           support=np.where(support[:, :, n], 'interpolation', 'extrapolation').tolist()))
    dump(out/'heatpump-surface-matrix.json', dict(schema=3, modelVersion=model['version'],
                                                outside_temperature_C=temperatures.tolist(),
                                                power_percent=powers.tolist(), slices=slices))
    print(json.dumps({k: validation[k] for k in ['selectedAlpha', 'previous_v5', 'nested', 'in_sample', 'folds']}, indent=2))


if __name__ == '__main__':
    main()
