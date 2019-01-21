import * as React from 'react';
import Grid from '@material-ui/core/Grid';
import Input from '@material-ui/core/Input';
import InputLabel from '@material-ui/core/InputLabel';
import MenuItem from '@material-ui/core/MenuItem';
import FormControl from '@material-ui/core/FormControl';
import Typography from '@material-ui/core/Typography';
import Select from '@material-ui/core/Select';
import Button from '@material-ui/core/Button';
import {withStyles} from "@material-ui/core";
import APILogsParameters from './APILogsParameters';
import moment from 'moment';
import {compose} from "recompose";
import {withGlobalCommands} from "../GlobalCommands";
import isEqual from 'lodash.isequal';
import DBLogsParameters from "./DBLogsParameters";


const styles = {
  parametersForm: {
    padding: '12px',
  },
  fields: {
  }
}


const toStringDate = (value) => {
  var dateRegex = /^\d+$/;
  if (dateRegex.test(value)) {
    return moment(value * 1000).format(displayDateFromat);
  } else if (moment.isMoment(value)) {
    return value.format(displayDateFromat);
  }
  return moment(value).format(displayDateFromat);
}

const toUnixDate = (date) => {
  const parsedDate = moment(date);
  return parsedDate.isValid() ? parsedDate.format('X') : ""
}

const defaultParameters = {
  type: "",
  textFilter: { type: 'match', text: ''},
  fromTime:moment().add(-1, 'days').startOf('day'),
  toTime: moment().add(-1, 'days').endOf('day'),
  server: "",
  session: "",
  table: "",
  objectId: "",
  logTypes: "apiV3, ps2"
}

const displayDateFromat = 'YYYY-MM-DD HH:mm';

class Parameters extends React.Component
{
  parametersFormRef = React.createRef();

  static defaultProps = {

  }

  state = {
    parameters: null,
    prevSearchParams: null
  }

  _fixSearchParams = (parameters) => {
    const result = {
      ...defaultParameters,
      ...(parameters || {})
    };

    result.fromTime = result.fromTime ? toStringDate(result.fromTime) : "";
    result.toTime = result.toTime ? toStringDate(result.toTime) : "";

    return result;
  }

  // TODO unify with handleSearch
  _updateSearchParams = (parameters, updateUrl) => {
    this.setState({
      parameters : this._fixSearchParams(parameters)
    }, () => {
      this._handleSearch(updateUrl);
    });
  }

  componentWillUnmount() {
    this.props.globalCommands.removeOnSearchParamsChanged();
    window.onpopstate = null;
  }

  onPopState = (e) => {
    const { searchParams: urlQueryParams } = this.props.globalCommands.extractQueryString();
    const compareTo = this._fixSearchParams(urlQueryParams);
    const { parameters }  = this.state;
    if (!isEqual(parameters, compareTo))
    {
      this._updateSearchParams(urlQueryParams, false);
    }
  }
  componentDidMount() {
    const {globalCommands} = this.props;

    window.onpopstate = this.onPopState;

    globalCommands.addOnSearchParamsChanged((parameters) => this._updateSearchParams(parameters, true));

    const initialParameters = this._fixSearchParams(globalCommands.getInitialParameters());
    this.setState({
        parameters: initialParameters
      }, () => {
        if (this.state.parameters.type) {
          this._handleSearch(false);
        }
      }
    );

  }

  _handleTextFilter = (text) => {
    this.setState(state => ({
      parameters: {
        ...state.parameters,
        textFilter: {
          type: 'match',
          text: text
        }
      }
    }))
  }

  _handleChange = (e) => {
    const { name, value } = e.target;
    this.setState(state => {
      return (
        {
          parameters: {
            ...state.parameters,
            [name]: value
          }
        }
      )
    })
  }


  validateDate = (date) => {
    if (!date) {
      return false;
    }

    return moment(date).isValid();
  }

  _handleSearch = (updateUrl = true) => {

    const {parameters: rawParameters} = this.state;
    const {onSearch, globalCommands } = this.props;

    let isValid = false;
    if (this.parametersFormRef.current) {
      isValid = this.parametersFormRef.current.validate();
    }

    if (!isValid) {
      return;
    }

    let searchParameters =
      {
        ...rawParameters,
        fromTime: toUnixDate(rawParameters.fromTime),
        toTime: toUnixDate(rawParameters.toTime),
      };

    searchParameters = this.parametersFormRef.current.filterParameters(searchParameters);

    const removeTextFilter = !rawParameters.textFilter || !rawParameters.textFilter.text;
    if (removeTextFilter) {
      delete searchParameters.textFilter;
    }

    if (updateUrl) {
      globalCommands.updateURL(searchParameters);
    }

    onSearch(searchParameters)
  }

  render() {
    const { parameters } = this.state;
    const { classes, onSearch } = this.props;

    if (!parameters) {
      return null;
    }
    return (
      <div style={{width: '100%', height: '100%'}}>
      <Grid  container spacing={16} alignItems={'flex-end'}>
        <Grid item xs={4}>
          <Typography variant={'headline'}>Search parameters</Typography>
        </Grid>
          <Grid item xs={4}>
            <FormControl fullWidth>
              <InputLabel shrink htmlFor="type-input">
                Select search type
              </InputLabel>
              <Select
                value={parameters.type}
                onChange={this._handleChange}
                input={<Input name="type" id="type-input" />}
                displayEmpty
                name="type"
              >
                <MenuItem value="">
                  <em>Select...</em>
                </MenuItem>
                <MenuItem value={'apiLogFilter'}>API Logs</MenuItem>
                <MenuItem value={'dbWritesFilter'}>Database changes</MenuItem>
                {/*<MenuItem value={'batchJobs'}>Batch jobs</MenuItem>*/}
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={4}>
            <Button disabled={!parameters.type} variant="contained" style={{float: 'right'}} onClick={this._handleSearch}>
              Search
            </Button>
          </Grid>
        <Grid item xs={12}>
          { parameters.type === 'apiLogFilter' && <APILogsParameters ref={this.parametersFormRef} {...this.state.parameters} onTextFilterChange={this._handleTextFilter} onChange={this._handleChange} className={classes.parametersForm}></APILogsParameters> }
          { parameters.type === 'dbWritesFilter' && <DBLogsParameters ref={this.parametersFormRef} {...this.state.parameters} onTextFilterChange={this._handleTextFilter} onChange={this._handleChange} className={classes.parametersForm}></DBLogsParameters> }
        </Grid>
      </Grid>
      <div style={{background: 'rgba(0, 0, 0, 0.5)'}}></div>
    </div>
    )
  }
}

export default compose(
  withStyles(styles),
  withGlobalCommands
)(Parameters);
